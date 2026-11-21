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

namespace Markocupic\ContaoClassBook\Service;

use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;

/**
 * Deletes the account of a member together with everything the member has
 * entered in the class book.
 */
final class AccountService
{
    private const MEMBER_TABLE = 'tl_member';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * All tables with data of members, including the tables that link two resources.
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        $tables = [];

        foreach (ResourceDefinitions::RESOURCES as $definition) {
            foreach ($definition['fields'] as $config) {
                if ('references' === $config['type']) {
                    $tables[] = $config['table'];
                }
            }

            $tables[] = $definition['table'];
        }

        return array_values(array_unique($tables));
    }

    /**
     * Deletes the data of the member and the member itself. This cannot be undone.
     */
    public function delete(int $memberId): void
    {
        $this->connection->transactional(
            function () use ($memberId): void {
                $this->deleteData($memberId);

                $this->connection->delete(self::MEMBER_TABLE, ['id' => $memberId]);
            },
        );
    }

    /**
     * Deletes everything the member has entered: every table has the column "member".
     */
    public function deleteData(int $memberId): void
    {
        foreach ($this->getTables() as $table) {
            $this->connection->delete($table, ['member' => $memberId]);
        }
    }
}
