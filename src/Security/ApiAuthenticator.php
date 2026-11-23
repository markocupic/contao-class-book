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
use Markocupic\ContaoClassBook\Service\ShareService;
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
        private readonly ShareService $shares,
    ) {
    }

    /**
     * Returns the id of the member whose class book the request works on, or null.
     *
     * That is the logged in member or, with a token for a shared class book, its owner.
     * The share is looked up with every request, so it ends as soon as it is revoked.
     */
    public function getMemberId(Request $request): int|null
    {
        return $this->getAccess($request)['memberId'] ?? null;
    }

    /**
     * Whether the request may change the class book: always in the own one, in a
     * shared one only with the right to write.
     */
    public function canWrite(Request $request): bool
    {
        return true === ($this->getAccess($request)['write'] ?? false);
    }

    /**
     * Returns the logged in member, also while the app shows a shared class book.
     * Everything about the account (user name, password, two-factor, shares) uses this.
     */
    public function getMember(Request $request): FrontendUser|null
    {
        return $this->authenticate($request)['user'] ?? null;
    }

    /**
     * The content element the page of the request was rendered for, or null.
     */
    public function getElementId(Request $request): int|null
    {
        return $this->tokenManager->validate((string) $request->headers->get(self::HEADER, ''))?->elementId;
    }

    /**
     * Whether the member has logged in with the password in this session
     * (and not with a "remember me" cookie).
     */
    public function isFullyAuthenticated(): bool
    {
        return $this->security->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * @return array{memberId: int, write: bool}|null
     */
    private function getAccess(Request $request): array|null
    {
        $auth = $this->authenticate($request);

        if (null === $auth) {
            return null;
        }

        $memberId = (int) $auth['user']->id;
        $ownerId = $auth['token']->ownerId;

        if ($ownerId === $memberId) {
            return ['memberId' => $memberId, 'write' => true];
        }

        $access = $this->shares->getAccess($ownerId, $memberId);

        if (null === $access) {
            return null;
        }

        return ['memberId' => $ownerId, 'write' => ShareService::ACCESS_WRITE === $access];
    }

    /**
     * @return array{user: FrontendUser, token: AccessToken}|null
     */
    private function authenticate(Request $request): array|null
    {
        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser || !$this->security->isGranted(self::ROLE)) {
            return null;
        }

        $token = $this->tokenManager->validate((string) $request->headers->get(self::HEADER, ''));

        if (null === $token || $token->memberId !== (int) $user->id) {
            return null;
        }

        return ['user' => $user, 'token' => $token];
    }
}
