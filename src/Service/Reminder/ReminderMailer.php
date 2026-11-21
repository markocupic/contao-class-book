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
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Controller\ContentElement\ClassBookController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends a reminder by e-mail to the address of the member, in the language of the member:
 * a greeting, the website the reminder comes from, the entry, a button that opens the class
 * book and a note that the e-mail is automatic. The e-mail has an HTML version (Twig
 * template, can be overridden) and a plain text version with the same content.
 */
final class ReminderMailer implements ReminderMailerInterface
{
    private const DOMAIN = 'contao_default';

    private const FALLBACK_LOCALE = 'de';

    // Can be overridden in the templates folder of the installation
    private const TEMPLATE = '@Contao/email/class_book_reminder.html.twig';

    // The colours of the entry types in the app (light theme)
    private const TYPE_COLORS = [
        'remark' => '#15803d',
        'note' => '#475569',
        'task' => '#475569',
        'appointment' => '#1d4ed8',
        'exam' => '#dc2626',
        'parentMeeting' => '#c2410c',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $urlGenerator,
        private readonly Environment $twig,
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
        $page = $this->getPage();
        $domain = $page instanceof PageModel ? trim((string) $page->domain) : '';

        // The same content for the HTML and the plain text version
        $data = [
            'lang' => $locale,
            'title' => $this->trans('title', $locale),
            'greeting' => trim($this->trans('reminderGreeting', $locale, [$firstname])),
            'intro' => $this->trans('reminderIntro', $locale, ['' !== $domain ? $domain : $this->trans('title', $locale)]),
            'type' => $this->trans('options.type.'.$entry['type'], $locale),
            'type_color' => self::TYPE_COLORS[$entry['type']] ?? self::TYPE_COLORS['note'],
            'date' => $start->format('d.m.Y'),
            'when' => $when,
            'location' => '' !== $entry['location'] ? $this->trans('fields.location', $locale).': '.$entry['location'] : null,
            'content' => $entry['content'],
            'url' => $this->getUrl($page),
            'open_label' => $this->trans('reminderOpen', $locale),
            'footer' => $this->trans('reminderFooter', $locale),
        ];

        $text = implode("\n", [
            $data['greeting'],
            '',
            $data['intro'],
            '',
            $data['type'].': '.$data['date'].', '.$data['when'],
            '',
            $data['content'],
            ...(null === $data['location'] ? [] : [$data['location']]),
            '',
            ...(null === $data['url'] ? [] : [$data['open_label'].': '.$data['url'], '']),
            '',
            $data['footer'],
        ]);

        $email = (new Email())
            ->to(trim((string) $member['email']))
            ->subject($this->trans('reminderSubject', $locale).': '.$title)
            ->text($text)
            ->html($this->twig->render(self::TEMPLATE, $data))
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
     * The page the class book runs on: the page with the first visible
     * class book element, with the details of its website. Null if there is none.
     */
    private function getPage(): PageModel|null
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

        return $page instanceof PageModel ? $page : null;
    }

    /**
     * The address of the class book for the button in the e-mail. The e-mail is sent
     * by a cron job without a request, so the address may not be available: then the
     * e-mail has no button.
     */
    private function getUrl(PageModel|null $page): string|null
    {
        if (null === $page || '' === trim((string) $page->domain)) {
            return null;
        }

        try {
            return $this->urlGenerator->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
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
