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

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Keeps a member on the page of the class book between the password and the two-factor code.
 *
 * After the password Contao redirects to the same page. As long as the code is missing,
 * Contao only shows a page that is stored as the target path in the session, every other
 * page ends with "not authenticated" (TwoFactorFrontendListener of the core). Contao stores
 * the target path itself only when that error has happened, which is why the form for the
 * code appeared after a reload only. This listener stores it right after the password
 * has been accepted in the login form of the class book.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class TwoFactorTargetPathListener
{
    use TargetPathTrait;

    public function __construct(
        #[Autowire(service: 'security.token_storage')]
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->isMethod('POST') || '1' !== $request->request->get(LoginIdentifierListener::FIELD)) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (!$token instanceof TwoFactorTokenInterface || !$request->hasSession()) {
            return;
        }

        // The same format the core compares the current URL with
        $this->saveTargetPath($request->getSession(), $token->getFirewallName(), $request->getSchemeAndHttpHost().$request->getRequestUri());
    }
}
