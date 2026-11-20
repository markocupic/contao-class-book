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

namespace Markocupic\ContaoClassBook\Service\MasterData;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Exception\RecordInUseException;
use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Reads and writes the master data of a member: school years, holidays,
 * periods, subjects, learning groups, students and the lessons of the timetable.
 *
 * Every query is limited to the records of the given member.
 */
final class MasterDataService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MasterDataValidator $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, list<array<string, int|string|list<int>>>>
     */
    public function getAll(int $memberId): array
    {
        $data = [];

        foreach (array_keys(ResourceDefinitions::RESOURCES) as $resource) {
            $data[$resource] = $this->getList($memberId, $resource);
        }

        return $data;
    }

    /**
     * @return list<array<string, int|string|list<int>>>
     */
    public function getList(int $memberId, string $resource): array
    {
        $definition = ResourceDefinitions::get($resource);

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT * FROM %s WHERE member = ? ORDER BY %s', $definition['table'], $definition['orderBy']),
            [$memberId],
        );

        // The ids of the junction tables, grouped by field and record
        $references = [];

        foreach ($definition['fields'] as $name => $config) {
            if ('references' !== $config['type']) {
                continue;
            }

            $links = $this->connection->fetchAllAssociative(
                \sprintf('SELECT %1$s AS owner, %2$s AS target FROM %3$s WHERE member = ? ORDER BY %2$s', $config['ownerColumn'], $config['targetColumn'], $config['table']),
                [$memberId],
            );

            foreach ($links as $link) {
                $references[$name][(int) $link['owner']][] = (int) $link['target'];
            }
        }

        $records = [];

        foreach ($rows as $row) {
            $record = ['id' => (int) $row['id']];

            foreach ($definition['fields'] as $name => $config) {
                $record[$name] = match ($config['type']) {
                    'references' => $references[$name][$record['id']] ?? [],
                    'integer', 'reference' => (int) $row[$name],
                    'string' => StringUtil::decodeEntities((string) $row[$name]),
                    default => (string) $row[$name],
                };
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Creates a record or updates the record with the given id.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, int|string|list<int>>
     *
     * @throws ValidationException
     * @throws RecordNotFoundException
     */
    public function save(int $memberId, string $resource, int|null $id, array $input): array
    {
        $definition = ResourceDefinitions::get($resource);
        $current = null;

        if (null !== $id) {
            $current = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE id = ? AND member = ?', $definition['table']),
                [$id, $memberId],
            );

            if (false === $current) {
                throw new RecordNotFoundException();
            }
        }

        $values = $this->validator->validate(
            $resource,
            $input,
            fn (string $referencedResource, int $referencedId, array $where): bool => $this->exists($memberId, $referencedResource, $referencedId, $where),
        );

        if (isset($definition['unique']) && $this->isDuplicate($memberId, $definition, $values, $id)) {
            $fields = $definition['unique'];

            throw new ValidationException([end($fields) => MasterDataValidator::ERROR_NOT_UNIQUE]);
        }

        $row = ['tstamp' => $this->clock->now()->getTimestamp()];
        $links = [];

        foreach ($definition['fields'] as $name => $config) {
            if ('references' === $config['type']) {
                $links[$name] = $values[$name];

                continue;
            }

            // Other records rely on the value, e.g. students on the kind of their home class
            if (null !== $current && ($config['lockedWhenReferenced'] ?? false) && (string) $current[$name] !== (string) $values[$name] && $this->isReferenced($memberId, $resource, $id)) {
                throw new ValidationException([$name => MasterDataValidator::ERROR_LOCKED]);
            }

            // Contao stores strings HTML encoded
            $row[$name] = 'string' === $config['type'] ? StringUtil::specialchars($values[$name]) : $values[$name];
        }

        // The record and its links are written together or not at all
        $id = $this->connection->transactional(
            function () use ($definition, $row, $links, $memberId, $id): int {
                if (null === $id) {
                    $this->connection->insert($definition['table'], [...$row, 'member' => $memberId]);
                    $id = (int) $this->connection->lastInsertId();
                } else {
                    $this->connection->update($definition['table'], $row, ['id' => $id, 'member' => $memberId]);
                }

                foreach ($links as $name => $targetIds) {
                    $config = $definition['fields'][$name];

                    $this->connection->delete($config['table'], [$config['ownerColumn'] => $id, 'member' => $memberId]);

                    foreach ($targetIds as $targetId) {
                        $this->connection->insert($config['table'], [
                            'tstamp' => $row['tstamp'],
                            'member' => $memberId,
                            $config['ownerColumn'] => $id,
                            $config['targetColumn'] => $targetId,
                        ]);
                    }
                }

                return $id;
            },
        );

        return ['id' => $id, ...$values];
    }

    /**
     * @throws RecordNotFoundException
     * @throws RecordInUseException
     */
    public function delete(int $memberId, string $resource, int $id): void
    {
        $definition = ResourceDefinitions::get($resource);

        if (!$this->exists($memberId, $resource, $id)) {
            throw new RecordNotFoundException();
        }

        if ($this->isReferenced($memberId, $resource, $id)) {
            throw new RecordInUseException();
        }

        $this->connection->transactional(
            function () use ($definition, $memberId, $id): void {
                foreach ($definition['fields'] as $config) {
                    if ('references' === $config['type']) {
                        $this->connection->delete($config['table'], [$config['ownerColumn'] => $id, 'member' => $memberId]);
                    }
                }

                $this->connection->delete($definition['table'], ['id' => $id, 'member' => $memberId]);
            },
        );
    }

    /**
     * @param array<string, string> $where Column values the record has to have
     */
    private function exists(int $memberId, string $resource, int $id, array $where = []): bool
    {
        $sql = \sprintf('SELECT id FROM %s WHERE id = ? AND member = ?', ResourceDefinitions::get($resource)['table']);
        $params = [$id, $memberId];

        foreach ($where as $column => $value) {
            $sql .= \sprintf(' AND %s = ?', $column);
            $params[] = $value;
        }

        return false !== $this->connection->fetchOne($sql, $params);
    }

    /**
     * Checks whether another record of the member has the same values in the unique fields.
     *
     * @param array{table: string, unique: list<string>} $definition
     * @param array<string, int|string|list<int>>        $values
     */
    private function isDuplicate(int $memberId, array $definition, array $values, int|null $id): bool
    {
        $sql = \sprintf('SELECT id FROM %s WHERE member = ? AND id != ?', $definition['table']);
        $params = [$memberId, $id ?? 0];

        foreach ($definition['unique'] as $field) {
            $sql .= \sprintf(' AND %s = ?', $field);
            $params[] = $values[$field];
        }

        return false !== $this->connection->fetchOne($sql, $params);
    }

    /**
     * Checks whether a record of the member points to the given record.
     */
    private function isReferenced(int $memberId, string $resource, int $id): bool
    {
        foreach (ResourceDefinitions::getReferencesTo($resource) as $reference) {
            $config = $reference['config'];

            if ('references' === $config['type']) {
                $table = $config['table'];
                $column = $config['targetColumn'];
            } else {
                $table = ResourceDefinitions::get($reference['resource'])['table'];
                $column = $reference['field'];
            }

            $count = $this->connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE member = ? AND %s = ?', $table, $column),
                [$memberId, $id],
            );

            if ($count > 0) {
                return true;
            }
        }

        return false;
    }
}
