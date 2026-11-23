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

use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class AccessTokenManagerTest extends TestCase
{
    public function testCreatesAValidToken(): void
    {
        $manager = new AccessTokenManager('secret', new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));
        $token = $manager->validate($manager->create(42, 7));

        $this->assertNotNull($token);
        $this->assertSame(42, $token->memberId);
        $this->assertSame(7, $token->elementId);
        $this->assertSame(42, $token->ownerId);
    }

    public function testATokenCanNameTheOwnerOfASharedClassBook(): void
    {
        $manager = new AccessTokenManager('secret', new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));
        $token = $manager->validate($manager->create(42, 7, 9));

        $this->assertNotNull($token);
        $this->assertSame(42, $token->memberId);
        $this->assertSame(9, $token->ownerId);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00'));

        $manager = new AccessTokenManager('secret', $clock);
        $token = $manager->create(42, 7);

        $clock->modify('+'.AccessTokenManager::TTL.' seconds');
        $this->assertNotNull($manager->validate($token));

        $clock->modify('+1 second');
        $this->assertNull($manager->validate($token));
    }

    public function testRejectsATamperedToken(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00'));
        $manager = new AccessTokenManager('secret', $clock);
        [, $signature] = explode('.', $manager->create(42, 7));

        $payload = rtrim(strtr(base64_encode(json_encode(['m' => 1, 'mod' => 7, 'exp' => 9999999999])), '+/', '-_'), '=');

        $this->assertNull($manager->validate($payload.'.'.$signature));
    }

    public function testRejectsATokenSignedWithAnotherSecret(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00'));
        $token = (new AccessTokenManager('other-secret', $clock))->create(42, 7);

        $this->assertNull((new AccessTokenManager('secret', $clock))->validate($token));
    }

    public function testRejectsMalformedTokens(): void
    {
        $manager = new AccessTokenManager('secret', new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));

        $this->assertNull($manager->validate(''));
        $this->assertNull($manager->validate('foo'));
        $this->assertNull($manager->validate('foo.bar'));
        $this->assertNull($manager->validate('foo.bar.baz'));
    }
}
