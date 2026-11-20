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
 * periods, subjects and learning groups.
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
     * @return array<string, list<array<string, int|string>>>
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
     * @return list<array<string, int|string>>
     */
    public function getList(int $memberId, string $resource): array
    {
        $definition = ResourceDefinitions::get($resource);

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT * FROM %s WHERE member = ? ORDER BY %s', $definition['table'], $definition['orderBy']),
            [$memberId],
        );

        return array_map(fn (array $row): array => $this->toRecord($resource, $row), $rows);
    }

    /**
     * Creates a record or updates the record with the given id.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, int|string>
     *
     * @throws ValidationException
     * @throws RecordNotFoundException
     */
    public function save(int $memberId, string $resource, int|null $id, array $input): array
    {
        $definition = ResourceDefinitions::get($resource);

        if (null !== $id && !$this->exists($memberId, $resource, $id)) {
            throw new RecordNotFoundException();
        }

        $values = $this->validator->validate(
            $resource,
            $input,
            fn (string $referencedResource, int $referencedId): bool => $this->exists($memberId, $referencedResource, $referencedId),
        );

        $row = ['tstamp' => $this->clock->now()->getTimestamp()];

        foreach ($definition['fields'] as $name => $config) {
            // Contao stores strings HTML encoded
            $row[$name] = 'string' === $config['type'] ? StringUtil::specialchars($values[$name]) : $values[$name];
        }

        if (null === $id) {
            $this->connection->insert($definition['table'], [...$row, 'member' => $memberId]);
            $id = (int) $this->connection->lastInsertId();
        } else {
            $this->connection->update($definition['table'], $row, ['id' => $id, 'member' => $memberId]);
        }

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

        foreach (ResourceDefinitions::getReferencesTo($resource) as $reference) {
            $count = $this->connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE member = ? AND %s = ?', ResourceDefinitions::get($reference['resource'])['table'], $reference['field']),
                [$memberId, $id],
            );

            if ($count > 0) {
                throw new RecordInUseException();
            }
        }

        $this->connection->delete($definition['table'], ['id' => $id, 'member' => $memberId]);
    }

    private function exists(int $memberId, string $resource, int $id): bool
    {
        return false !== $this->connection->fetchOne(
            \sprintf('SELECT id FROM %s WHERE id = ? AND member = ?', ResourceDefinitions::get($resource)['table']),
            [$id, $memberId],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int|string>
     */
    private function toRecord(string $resource, array $row): array
    {
        $record = ['id' => (int) $row['id']];

        foreach (ResourceDefinitions::get($resource)['fields'] as $name => $config) {
            $record[$name] = match ($config['type']) {
                'integer', 'reference' => (int) $row[$name],
                'string' => StringUtil::decodeEntities((string) $row[$name]),
                default => (string) $row[$name],
            };
        }

        return $record;
    }
}
