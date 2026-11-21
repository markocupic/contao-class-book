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

namespace Markocupic\ContaoClassBook\Service\Registration;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends the e-mail with the activation link of a registration: an HTML version in the
 * design of the reminder e-mail (Twig template, can be overridden) and a plain text
 * version with the same content.
 */
final class RegistrationMailer
{
    private const DOMAIN = 'contao_default';

    // Can be overridden in the templates folder of the installation
    private const TEMPLATE = '@Contao/email/class_book_activation.html.twig';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param string $host the website the teacher has registered on
     * @param string $link the activation link
     *
     * @return array{subject: string, text: string} what was sent, for the opt-in record
     */
    public function send(string $email, string $firstname, string $host, string $link, string $locale): array
    {
        $data = [
            'lang' => $locale,
            'title' => $this->trans('title', $locale),
            'greeting' => trim($this->trans('reminderGreeting', $locale, [$firstname])),
            'intro' => $this->trans('registerMailIntro', $locale, [$host]),
            'url' => $link,
            'button' => $this->trans('registerMailButton', $locale),
            'link_hint' => $this->trans('registerMailLink', $locale),
            'valid' => $this->trans('registerMailValid', $locale),
            'footer' => $this->trans('reminderFooter', $locale),
        ];

        $subject = $this->trans('registerMailSubject', $locale, [$host]);

        $text = implode("\n", [
            $data['greeting'],
            '',
            $data['intro'],
            '',
            $data['button'].': '.$link,
            '',
            $data['valid'],
            '',
            $data['footer'],
        ]);

        $message = (new Email())
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html($this->twig->render(self::TEMPLATE, $data))
        ;

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
