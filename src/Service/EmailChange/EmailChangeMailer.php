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

namespace Markocupic\ContaoClassBook\Service\EmailChange;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Markocupic\ContaoClassBook\Service\Mail\LogoEmbedder;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The two e-mails of a changed e-mail address: the link for the new address and the
 * notice for the old one. Both use the template of the activation e-mail (HTML version
 * in the design of the app, can be overridden) and have a plain text version.
 */
final class EmailChangeMailer
{
    private const DOMAIN = 'contao_default';

    private const TEMPLATE = '@Contao/email/class_book_activation.html.twig';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
        private readonly Environment $twig,
        private readonly LogoEmbedder $logoEmbedder,
    ) {
    }

    /**
     * The link that confirms the new address, sent to the new address.
     *
     * @return array{subject: string, text: string} what was sent, for the opt-in record
     */
    public function sendLink(string $email, string $firstname, string $host, string $link, string $locale): array
    {
        return $this->send(
            $email,
            $locale,
            $this->trans('emailMailSubject', $locale, [$host]),
            [
                'greeting' => trim($this->trans('reminderGreeting', $locale, [$firstname])),
                'intro' => $this->trans('emailMailIntro', $locale, [$host]),
                'url' => $link,
                'button' => $this->trans('emailMailButton', $locale),
                'link_hint' => $this->trans('registerMailLink', $locale),
                'valid' => $this->trans('emailMailValid', $locale),
            ],
        );
    }

    /**
     * Tells the old address that the account has a new one, so that a change the
     * member did not make is noticed.
     *
     * @return array{subject: string, text: string}
     */
    public function sendNotice(string $email, string $firstname, string $host, string $newEmail, string $locale): array
    {
        return $this->send(
            $email,
            $locale,
            $this->trans('emailNoticeSubject', $locale, [$host]),
            [
                'greeting' => trim($this->trans('reminderGreeting', $locale, [$firstname])),
                'intro' => $this->trans('emailNoticeIntro', $locale, [$host, $newEmail]),
                'url' => null,
                'button' => null,
                'link_hint' => null,
                'valid' => $this->trans('emailNoticeHint', $locale),
            ],
        );
    }

    /**
     * @param array{greeting: string, intro: string, url: string|null, button: string|null, link_hint: string|null, valid: string} $data
     *
     * @return array{subject: string, text: string}
     */
    private function send(string $email, string $locale, string $subject, array $data): array
    {
        $data = [
            ...$data,
            'lang' => $locale,
            'title' => $this->trans('title', $locale),
            'footer' => $this->trans('reminderFooter', $locale),
        ];

        $lines = [$data['greeting'], '', $data['intro'], ''];

        if (null !== $data['url']) {
            $lines = [...$lines, $data['button'].': '.$data['url'], ''];
        }

        $text = implode("\n", [...$lines, $data['valid'], '', $data['footer']]);

        $message = (new Email())
            ->to($email)
            ->subject($subject)
            ->text($text)
        ;

        // The logo is part of the e-mail, the template refers to it by its content id
        $message->html($this->twig->render(self::TEMPLATE, [...$data, 'logo' => $this->logoEmbedder->embed($message)]));

        $from = $this->getSender();

        if (null !== $from) {
            $message->from($from);
        }

        $this->mailer->send($message);

        return ['subject' => $subject, 'text' => $text];
    }

    /**
     * @param list<string> $parameters
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans('MSC.classBook.'.$key, $parameters, self::DOMAIN, $locale);
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
