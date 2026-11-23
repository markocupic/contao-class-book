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

namespace Markocupic\ContaoClassBook\Tests\Security;

use Contao\FrontendUser;
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\ShareService;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

class ApiAuthenticatorTest extends TestCase
{
    private const OWNER = 1;

    private const GUEST = 2;

    private const ELEMENT = 7;

    private Connection $connection;

    private AccessTokenManager $tokenManager;

    private ShareService $shares;

    protected function setUp(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-23 08:00:00'));

        $this->connection = DatabaseFactory::create();
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', login INTEGER NOT NULL DEFAULT 1, disable INTEGER NOT NULL DEFAULT 0)");
        $this->connection->insert('tl_member', ['id' => self::OWNER, 'email' => 'owner@example.org']);
        $this->connection->insert('tl_member', ['id' => self::GUEST, 'email' => 'guest@example.org']);

        $this->tokenManager = new AccessTokenManager('secret', $clock);
        $this->shares = new ShareService($this->connection, $clock);
    }

    public function testTheOwnClassBookCanBeReadAndChanged(): void
    {
        $authenticator = $this->createAuthenticator(self::GUEST);
        $request = $this->createRequest($this->tokenManager->create(self::GUEST, self::ELEMENT));

        $this->assertSame(self::GUEST, $authenticator->getMemberId($request));
        $this->assertTrue($authenticator->canWrite($request));
        $this->assertNotNull($authenticator->getMember($request));
        $this->assertSame(self::ELEMENT, $authenticator->getElementId($request));
    }

    public function testNothingWithoutLoginOrWithoutAToken(): void
    {
        $token = $this->tokenManager->create(self::GUEST, self::ELEMENT);

        foreach ([[$this->createAuthenticator(null), $token], [$this->createAuthenticator(self::GUEST, false), $token], [$this->createAuthenticator(self::GUEST), ''], [$this->createAuthenticator(self::GUEST), 'x.y']] as [$authenticator, $value]) {
            $request = $this->createRequest($value);

            $this->assertNull($authenticator->getMemberId($request));
            $this->assertNull($authenticator->getMember($request));
            $this->assertFalse($authenticator->canWrite($request));
        }
    }

    public function testATokenOfAnotherMemberIsRefused(): void
    {
        $authenticator = $this->createAuthenticator(self::GUEST);

        // The token of the owner, and a token the owner got for the class book of the guest
        foreach ([$this->tokenManager->create(self::OWNER, self::ELEMENT), $this->tokenManager->create(self::OWNER, self::ELEMENT, self::GUEST)] as $token) {
            $request = $this->createRequest($token);

            $this->assertNull($authenticator->getMemberId($request));
            $this->assertNull($authenticator->getMember($request));
            $this->assertFalse($authenticator->canWrite($request));
        }
    }

    public function testASharedClassBookNeedsTheShareWithEveryRequest(): void
    {
        $authenticator = $this->createAuthenticator(self::GUEST);
        $request = $this->createRequest($this->tokenManager->create(self::GUEST, self::ELEMENT, self::OWNER));

        // Without a share: no data, but the guest is still the logged in member
        $this->assertNull($authenticator->getMemberId($request));
        $this->assertFalse($authenticator->canWrite($request));
        $this->assertNotNull($authenticator->getMember($request));

        $this->shares->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_READ);

        $this->assertSame(self::OWNER, $authenticator->getMemberId($request));
        $this->assertFalse($authenticator->canWrite($request));

        $this->shares->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $this->assertSame(self::OWNER, $authenticator->getMemberId($request));
        $this->assertTrue($authenticator->canWrite($request));

        // Revoked: the same token is worth nothing any more
        $this->shares->revoke(self::OWNER, $this->shares->getGranted(self::OWNER)[0]['id']);

        $this->assertNull($authenticator->getMemberId($request));
        $this->assertFalse($authenticator->canWrite($request));
    }

    public function testAShareWorksInOneDirectionOnly(): void
    {
        $this->shares->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $authenticator = $this->createAuthenticator(self::OWNER);
        $request = $this->createRequest($this->tokenManager->create(self::OWNER, self::ELEMENT, self::GUEST));

        $this->assertNull($authenticator->getMemberId($request));
        $this->assertFalse($authenticator->canWrite($request));
    }

    /**
     * @param int|null $memberId the logged in member, null for nobody
     * @param bool     $complete false: the password was entered but not yet the two-factor code
     */
    private function createAuthenticator(int|null $memberId, bool $complete = true): ApiAuthenticator
    {
        $user = null;

        if (null !== $memberId) {
            $user = $this->createStub(FrontendUser::class);
            $user->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $memberId : null);
        }

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn($complete);

        return new ApiAuthenticator($security, $this->tokenManager, $this->shares);
    }

    private function createRequest(string $token): Request
    {
        $request = new Request();
        $request->headers->set(ApiAuthenticator::HEADER, $token);

        return $request;
    }
}
