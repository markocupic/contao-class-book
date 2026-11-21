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

namespace Markocupic\ContaoClassBook\Security;

use Contao\FrontendUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates an API request: a member has to be logged in and the request
 * has to carry a valid access token that was issued for exactly this member.
 *
 * A member who has entered the password but not yet the two-factor code is not
 * logged in: the token has no roles until the code is verified.
 */
final class ApiAuthenticator
{
    public const HEADER = 'X-Class-Book-Token';

    public const ROLE = 'ROLE_MEMBER';

    public function __construct(
        private readonly Security $security,
        private readonly AccessTokenManager $tokenManager,
    ) {
    }

    /**
     * Returns the id of the authenticated member or null.
     */
    public function getMemberId(Request $request): int|null
    {
        $user = $this->getMember($request);

        return null === $user ? null : (int) $user->id;
    }

    /**
     * Returns the authenticated member or null.
     */
    public function getMember(Request $request): FrontendUser|null
    {
        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser || !$this->security->isGranted(self::ROLE)) {
            return null;
        }

        $token = $this->tokenManager->validate((string) $request->headers->get(self::HEADER, ''));

        if (null === $token || $token->memberId !== (int) $user->id) {
            return null;
        }

        return $user;
    }

    /**
     * Whether the member has logged in with the password in this session
     * (and not with a "remember me" cookie).
     */
    public function isFullyAuthenticated(): bool
    {
        return $this->security->isGranted('IS_AUTHENTICATED_FULLY');
    }
}
