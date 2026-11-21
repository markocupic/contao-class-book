<?php

declare(strict_types=1);

/*
 * This file is part of Contao Class Book.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license MIT
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-class-book
 */

namespace Markocupic\ContaoClassBook\Service\Reminder;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Controller\ContentElement\ClassBookController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends a reminder as a plain text e-mail to the address of the member, in the language of the member:
 * a greeting, the website the reminder comes from, the entry and a note that the e-mail is automatic.
 */
final class ReminderMailer implements ReminderMailerInterface
{
    private const DOMAIN = 'contao_default';

    private const FALLBACK_LOCALE = 'de';

    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function send(array $entry, \DateTimeImmutable $start): void
    {
        $member = $this->connection->fetchAssociative('SELECT email, language, firstname FROM tl_member WHERE id = ?', [$entry['member']]);

        // Without an address there is nothing to send
        if (false === $member || '' === trim((string) $member['email'])) {
            return;
        }

        $locale = '' !== (string) $member['language'] ? (string) $member['language'] : self::FALLBACK_LOCALE;
        $title = trim((string) preg_replace('/\s+/', ' ', $entry['content']));

        if (mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 79).'…';
        }

        if ($entry['period'] > 0) {
            $when = \sprintf('%s %d (%s–%s)', $this->trans('fields.period', $locale), $entry['periodNumber'], $entry['periodStart'], $entry['periodEnd']);
        } elseif ('' !== $entry['entryTime']) {
            $when = $entry['entryTime'];
        } else {
            $when = $this->trans('wholeDay', $locale);
        }

        $firstname = trim(StringUtil::decodeEntities((string) $member['firstname']));

        $text = implode("\n", [
            trim($this->trans('reminderGreeting', $locale, [$firstname])),
            '',
            $this->trans('reminderIntro', $locale, [$this->getDomain() ?? $this->trans('title', $locale)]),
            '',
            $this->trans('options.type.'.$entry['type'], $locale).': '.$start->format('d.m.Y').', '.$when,
            '',
            $entry['content'],
            '',
            '',
            $this->trans('reminderFooter', $locale),
        ]);

        $email = (new Email())
            ->to(trim((string) $member['email']))
            ->subject($this->trans('reminderSubject', $locale).': '.$title)
            ->text($text)
        ;

        $from = $this->getSender();

        if (null !== $from) {
            $email->from($from);
        }

        $this->mailer->send($email);
    }

    /**
     * @param list<string> $parameters
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans('MSC.classBook.'.$key, $parameters, self::DOMAIN, $locale);
    }

    /**
     * The domain of the website the class book runs on: the domain of the page
     * with the first visible class book element. Null if it has none.
     */
    private function getDomain(): string|null
    {
        $pageId = $this->connection->fetchOne(
            "SELECT a.pid FROM tl_content c INNER JOIN tl_article a ON a.id = c.pid
            WHERE c.type = ? AND c.ptable = 'tl_article' AND c.invisible = 0
            ORDER BY c.id",
            [ClassBookController::TYPE],
        );

        if (false === $pageId) {
            return null;
        }

        $this->framework->initialize();

        $page = $this->framework->getAdapter(PageModel::class)->findWithDetails((int) $pageId);
        $domain = $page instanceof PageModel ? trim((string) $page->domain) : '';

        return '' === $domain ? null : $domain;
    }

    /**
     * The e-mail address of the administrator from the Contao settings.
     * Without it the mailer uses its own default sender.
     */
    private function getSender(): Address|null
    {
        $this->framework->initialize();

        $adminEmail = (string) $this->framework->getAdapter(Config::class)->get('adminEmail');

        if ('' === $adminEmail) {
            return null;
        }

        [$name, $address] = $this->framework->getAdapter(StringUtil::class)->splitFriendlyEmail($adminEmail);

        return new Address((string) $address, (string) $name);
    }
}
