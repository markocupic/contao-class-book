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
use Markocupic\ContaoClassBook\Service\AccountService;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use PHPUnit\Framework\TestCase;

class AccountServiceTest extends TestCase
{
    public function testKnowsAllTablesWithDataOfMembers(): void
    {
        $tables = (new AccountService(DatabaseFactory::create()))->getTables();

        $this->assertTrue(\in_array('tl_cb_entry', $tables, true));
        $this->assertTrue(\in_array('tl_cb_student', $tables, true));
        $this->assertTrue(\in_array('tl_cb_student_group', $tables, true));
        $this->assertCount(\count(array_unique($tables)), $tables);
    }

    public function testDeletesTheMemberWithAllDataAndNothingElse(): void
    {
        $connection = DatabaseFactory::create();

        $this->createCoreTables($connection);

        $service = new AccountService($connection);

        foreach ([1, 2] as $memberId) {
            $connection->insert('tl_member', ['id' => $memberId, 'email' => $memberId.'@example.org']);

            foreach ($service->getTables() as $table) {
                $connection->insert($table, ['member' => $memberId]);
                $connection->insert($table, ['member' => $memberId]);
            }
        }

        $service->delete(1);

        foreach ($service->getTables() as $table) {
            $this->assertSame(0, $this->countRows($connection, $table, 1));
            $this->assertSame(2, $this->countRows($connection, $table, 2));
        }

        $this->assertSame([2], array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM tl_member')));
    }

    public function testDeletesTheDataOfMembersThatDoNotExistAnyMore(): void
    {
        $connection = DatabaseFactory::create();

        $this->createCoreTables($connection);

        $connection->insert('tl_member', ['id' => 2, 'email' => '2@example.org']);

        $service = new AccountService($connection);

        // Member 1 is gone, member 2 exists, 0 is a row without a member
        foreach ([0, 1, 2] as $memberId) {
            foreach ($service->getTables() as $table) {
                $connection->insert($table, ['member' => $memberId]);
            }
        }

        $this->assertSame(\count($service->getTables()), $service->deleteOrphans());

        foreach ($service->getTables() as $table) {
            $this->assertSame(0, $this->countRows($connection, $table, 1));
            $this->assertSame(1, $this->countRows($connection, $table, 2));
            $this->assertSame(1, $this->countRows($connection, $table, 0));
        }

        $this->assertSame(0, $service->deleteOrphans());
    }

    public function testDeletesTheOptInTokensOfTheMember(): void
    {
        $connection = DatabaseFactory::create();

        $this->createCoreTables($connection);

        $connection->insert('tl_member', ['id' => 1, 'email' => '1@example.org']);
        $connection->insert('tl_member', ['id' => 2, 'email' => '2@example.org']);

        // Registration and changed address of member 1, registration of member 2, a member
        // that is gone (3), and a token of something else that happens to have the id 1
        $this->addOptIn($connection, 'reg-aaa', 'tl_member', 1);
        $this->addOptIn($connection, 'cbmail-bbb', 'tl_member', 1);
        $this->addOptIn($connection, 'reg-ccc', 'tl_member', 2);
        $this->addOptIn($connection, 'cbmail-ddd', 'tl_member', 3);
        $this->addOptIn($connection, 'nl-eee', 'tl_newsletter_recipients', 1);
        $this->addOptIn($connection, 'other-fff', 'tl_member', 1);

        $service = new AccountService($connection);
        $service->delete(1);

        $this->assertSame(['reg-ccc', 'cbmail-ddd', 'nl-eee', 'other-fff'], $connection->fetchFirstColumn('SELECT token FROM tl_opt_in ORDER BY id'));
        $this->assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM tl_opt_in_related'));

        // The token of the member that is gone
        $this->assertSame(1, $service->deleteOrphans());
        $this->assertSame(['reg-ccc', 'nl-eee', 'other-fff'], $connection->fetchFirstColumn('SELECT token FROM tl_opt_in ORDER BY id'));
    }

    /**
     * The tables of the Contao core the service works with.
     */
    public function testDeletesGivenAndReceivedSharesOfTheMember(): void
    {
        $connection = DatabaseFactory::create();

        $this->createCoreTables($connection);

        foreach ([1, 2, 3] as $memberId) {
            $connection->insert('tl_member', ['id' => $memberId, 'email' => $memberId.'@example.org']);
        }

        // 1 shares with 2, 2 shares with 1, 2 shares with 3, 3 shares with a member that is gone (4)
        foreach ([[1, 2], [2, 1], [2, 3], [3, 4]] as [$owner, $guest]) {
            $connection->insert('tl_cb_share', ['member' => $owner, 'guest' => $guest, 'guestEmail' => $guest.'@example.org']);
        }

        $service = new AccountService($connection);

        $this->assertSame(1, $service->deleteOrphans());

        $service->delete(1);

        $this->assertSame([['member' => 2, 'guest' => 3]], array_map(static fn (array $row): array => array_map(intval(...), $row), $connection->fetchAllAssociative('SELECT member, guest FROM tl_cb_share')));
    }

    private function createCoreTables(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE tl_opt_in (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE tl_opt_in_related (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL, relTable TEXT NOT NULL, relId INTEGER NOT NULL)');
    }

    private function addOptIn(Connection $connection, string $token, string $table, int $id): void
    {
        $connection->insert('tl_opt_in', ['token' => $token]);
        $connection->insert('tl_opt_in_related', ['pid' => (int) $connection->lastInsertId(), 'relTable' => $table, 'relId' => $id]);
    }

    private function countRows(Connection $connection, string $table, int $memberId): int
    {
        return (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE member = ?', $table), [$memberId]);
    }
}
