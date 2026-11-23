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

    // The double opt-in tokens of Contao that hold an e-mail address of the member and the
    // text of the e-mail: registration and changed e-mail address. Contao itself would keep
    // confirmed tokens for up to three years after the member is gone.
    private const OPT_IN_PREFIXES = ['reg', 'cbmail'];

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

        // The shares the member has given. The received ones have the member as guest
        $tables[] = ShareService::TABLE;

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
     * Deletes the data of members that do not exist any more, e.g. because the member was
     * deleted by the core (a registration that was never activated) or directly in the
     * database. Rows without a member (0) are left alone.
     *
     * @return int the number of deleted rows
     */
    public function deleteOrphans(): int
    {
        $deleted = 0;

        foreach ($this->getTables() as $table) {
            $deleted += (int) $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE member > 0 AND member NOT IN (SELECT id FROM %s)', $table, self::MEMBER_TABLE),
            );
        }

        $deleted += (int) $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE guest > 0 AND guest NOT IN (SELECT id FROM %s)', ShareService::TABLE, self::MEMBER_TABLE),
        );

        return $deleted + $this->deleteOptIns(null);
    }

    /**
     * Deletes everything the member has entered: every table has the column "member".
     * The shares the member has received and the opt-in tokens of the member go as well.
     */
    public function deleteData(int $memberId): void
    {
        foreach ($this->getTables() as $table) {
            $this->connection->delete($table, ['member' => $memberId]);
        }

        // The class books that were shared with the member
        $this->connection->delete(ShareService::TABLE, ['guest' => $memberId]);

        $this->deleteOptIns($memberId);
    }

    /**
     * Deletes the opt-in tokens of a member, or (null) of members that do not exist any more.
     *
     * @return int the number of deleted tokens
     */
    private function deleteOptIns(int|null $memberId): int
    {
        $prefixes = implode(' OR ', array_map(static fn (string $prefix): string => \sprintf("o.token LIKE '%s-%%'", $prefix), self::OPT_IN_PREFIXES));

        $ids = $this->connection->fetchFirstColumn(
            \sprintf(
                "SELECT DISTINCT o.id FROM tl_opt_in o INNER JOIN tl_opt_in_related r ON r.pid = o.id WHERE r.relTable = '%s' AND (%s) AND %s",
                self::MEMBER_TABLE,
                $prefixes,
                null === $memberId ? \sprintf('r.relId NOT IN (SELECT id FROM %s)', self::MEMBER_TABLE) : 'r.relId = ?',
            ),
            null === $memberId ? [] : [$memberId],
        );

        foreach ($ids as $id) {
            $this->connection->delete('tl_opt_in_related', ['pid' => $id]);
            $this->connection->delete('tl_opt_in', ['id' => $id]);
        }

        return \count($ids);
    }
}
