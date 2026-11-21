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

namespace Markocupic\ContaoClassBook\EventListener;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets a member log in with the e-mail address in the login form of the class book.
 *
 * Contao only knows the user name. If the form of the class book is submitted with
 * an e-mail address that belongs to exactly one member who may log in, the user
 * name of that member is passed on to the login of Contao. Everything else (the
 * password check, locking after failed attempts) stays as it is.
 *
 * The listener runs before the firewall (priority 8) but after the request token
 * check of Contao (priority 14): Contao skips that check for visitors without
 * cookies and without a session, so the session must not be started before it.
 *
 * The e-mail address the member has typed is kept in the session, so that the
 * form shows it again and never the user name.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final class LoginIdentifierListener
{
    // Hidden field that marks the login form of the class book
    public const FIELD = '_class_book_login';

    public const SESSION_KEY = 'class_book_login_identifier';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->isMethod('POST') || '1' !== $request->request->get(self::FIELD)) {
            return;
        }

        $formId = $request->request->get('FORM_SUBMIT');
        $identifier = $request->request->get('username');

        if (!\is_string($formId) || !preg_match('/^tl_login_\d+$/', $formId) || !\is_string($identifier)) {
            return;
        }

        if (!str_contains($identifier, '@')) {
            return;
        }

        $usernames = $this->connection->fetchFirstColumn(
            "SELECT username FROM tl_member WHERE email = ? AND login = 1 AND username != ''",
            [trim($identifier)],
        );

        if (1 !== \count($usernames)) {
            return;
        }

        $request->request->set('username', (string) $usernames[0]);

        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $identifier);
        }
    }
}
