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
        $connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

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

    private function countRows(Connection $connection, string $table, int $memberId): int
    {
        return (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE member = ?', $table), [$memberId]);
    }
}
