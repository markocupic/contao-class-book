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

namespace Markocupic\ContaoClassBook\Tests\Service;

use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Service\ShareService;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class ShareServiceTest extends TestCase
{
    private const OWNER = 1;

    private const GUEST = 2;

    private const STRANGER = 3;

    private Connection $connection;

    private ShareService $service;

    protected function setUp(): void
    {
        $this->connection = DatabaseFactory::create();
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', login INTEGER NOT NULL DEFAULT 1, disable INTEGER NOT NULL DEFAULT 0)");

        $this->addMember(self::OWNER, 'owner@example.org', 'Olga', 'Owner');
        $this->addMember(self::GUEST, 'Guest@Example.org', 'Gustav', 'Guest');
        $this->addMember(self::STRANGER, 'stranger@example.org', 'Stefan', 'Stranger');

        $this->service = new ShareService($this->connection, new FixedClock(new \DateTimeImmutable('2026-11-23 08:00:00')));
    }

    public function testWithoutAShareThereIsNoAccess(): void
    {
        $this->assertNull($this->service->getAccess(self::OWNER, self::GUEST));
        $this->assertNull($this->service->getAccess(self::OWNER, self::OWNER));
        $this->assertNull($this->service->getAccess(self::OWNER, 0));
        $this->assertSame([], $this->service->getReceived(self::GUEST));
    }

    public function testAShareGivesTheGuestAccessAndNobodyElse(): void
    {
        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_READ);

        $this->assertSame(ShareService::ACCESS_READ, $this->service->getAccess(self::OWNER, self::GUEST));
        $this->assertNull($this->service->getAccess(self::OWNER, self::STRANGER));

        // A share works in one direction only
        $this->assertNull($this->service->getAccess(self::GUEST, self::OWNER));
        $this->assertNull($this->service->getAccess(self::STRANGER, self::GUEST));
    }

    public function testTheGuestSeesWhoHasSharedAClassBook(): void
    {
        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $received = $this->service->getReceived(self::GUEST);

        $this->assertCount(1, $received);
        $this->assertSame(self::OWNER, $received[0]['owner']);
        $this->assertSame('Olga', $received[0]['firstname']);
        $this->assertSame(ShareService::ACCESS_WRITE, $received[0]['access']);
        $this->assertSame([], $this->service->getReceived(self::OWNER));
        $this->assertSame([], $this->service->getReceived(self::STRANGER));
    }

    public function testASecondShareForTheSameAddressChangesTheRight(): void
    {
        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_READ);
        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $granted = $this->service->getGranted(self::OWNER);

        $this->assertCount(1, $granted);
        $this->assertSame('guest@example.org', $granted[0]['email']);
        $this->assertSame(ShareService::ACCESS_WRITE, $this->service->getAccess(self::OWNER, self::GUEST));
    }

    public function testAShareForAnUnknownAddressWaitsForTheMember(): void
    {
        $this->service->grant(self::OWNER, 'new@example.org', ShareService::ACCESS_READ);

        // The owner sees the same as for a known address
        $this->assertSame(['new@example.org'], array_column($this->service->getGranted(self::OWNER), 'email'));

        $this->addMember(4, 'new@example.org', 'Nora', 'New');

        $this->assertNull($this->service->getAccess(self::OWNER, 4));
        $this->assertCount(1, $this->service->getReceived(4));
        $this->assertSame(ShareService::ACCESS_READ, $this->service->getAccess(self::OWNER, 4));

        // The share follows the member, not the address
        $this->connection->update('tl_member', ['email' => 'other@example.org'], ['id' => 4]);
        $this->addMember(5, 'new@example.org', 'Nils', 'Next');

        $this->assertSame([], $this->service->getReceived(5));
        $this->assertSame(ShareService::ACCESS_READ, $this->service->getAccess(self::OWNER, 4));
    }

    public function testAnAccountThatIsNotActivatedIsNotBoundWhenTheShareIsGiven(): void
    {
        $this->addMember(4, 'pending@example.org', 'Paula', 'Pending');
        $this->connection->update('tl_member', ['disable' => 1], ['id' => 4]);

        $this->service->grant(self::OWNER, 'pending@example.org', ShareService::ACCESS_WRITE);

        $this->assertNull($this->service->getAccess(self::OWNER, 4));
    }

    public function testOwnerAndGuestCanEndAShareAndNobodyElse(): void
    {
        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $id = $this->service->getGranted(self::OWNER)[0]['id'];

        $this->assertFalse($this->service->revoke(self::STRANGER, $id));
        $this->assertSame(ShareService::ACCESS_WRITE, $this->service->getAccess(self::OWNER, self::GUEST));

        $this->assertTrue($this->service->revoke(self::GUEST, $id));
        $this->assertNull($this->service->getAccess(self::OWNER, self::GUEST));

        $this->service->grant(self::OWNER, 'guest@example.org', ShareService::ACCESS_WRITE);

        $this->assertTrue($this->service->revoke(self::OWNER, $this->service->getGranted(self::OWNER)[0]['id']));
        $this->assertSame([], $this->service->getGranted(self::OWNER));
        $this->assertFalse($this->service->revoke(self::OWNER, 999));
    }

    public function testValidatesAddressAndRight(): void
    {
        $this->assertSame([], $this->service->validate(self::OWNER, 'guest@example.org', ShareService::ACCESS_READ));
        $this->assertSame(['email' => 'required'], $this->service->validate(self::OWNER, '', ShareService::ACCESS_READ));
        $this->assertSame(['email' => 'email'], $this->service->validate(self::OWNER, 'guest', ShareService::ACCESS_READ));
        $this->assertSame(['email' => 'tooLong'], $this->service->validate(self::OWNER, str_repeat('a', 250).'@example.org', ShareService::ACCESS_READ));
        $this->assertSame(['email' => 'same'], $this->service->validate(self::OWNER, 'owner@example.org', ShareService::ACCESS_READ));
        $this->assertSame(['access' => 'invalid'], $this->service->validate(self::OWNER, 'guest@example.org', 'admin'));
        $this->assertSame(['access' => 'invalid'], $this->service->validate(self::OWNER, 'guest@example.org', null));
        $this->assertSame('guest@example.org', $this->service->normalizeEmail('  Guest@Example.org '));
        $this->assertSame('', $this->service->normalizeEmail(['guest@example.org']));
    }

    public function testLimitsTheNumberOfShares(): void
    {
        for ($i = 0; $i < ShareService::MAX_SHARES; ++$i) {
            $this->service->grant(self::OWNER, 'guest'.$i.'@example.org', ShareService::ACCESS_READ);
        }

        $this->assertSame(['email' => 'tooMany'], $this->service->validate(self::OWNER, 'more@example.org', ShareService::ACCESS_READ));

        // The right of an existing share can still be changed
        $this->assertSame([], $this->service->validate(self::OWNER, 'guest0@example.org', ShareService::ACCESS_WRITE));
    }

    private function addMember(int $id, string $email, string $firstname, string $lastname): void
    {
        $this->connection->insert('tl_member', ['id' => $id, 'email' => $email, 'firstname' => $firstname, 'lastname' => $lastname]);
    }
}
