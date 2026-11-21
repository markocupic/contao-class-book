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
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends a reminder as a plain text e-mail to the address of the member, in the language of the member.
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
        $member = $this->connection->fetchAssociative('SELECT email, language FROM tl_member WHERE id = ?', [$entry['member']]);

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

        $text = implode("\n", [
            $this->trans('options.type.'.$entry['type'], $locale).': '.$start->format('d.m.Y').', '.$when,
            '',
            $entry['content'],
            '',
            '-- ',
            $this->trans('title', $locale),
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

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans('MSC.classBook.'.$key, [], self::DOMAIN, $locale);
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
