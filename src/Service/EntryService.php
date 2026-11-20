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

use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;

/**
 * The entries of the class book: notes, tasks, appointments and lesson remarks.
 *
 * An entry belongs to a date and either to a period of that day or to the
 * whole day (then with an optional time). Tasks can be marked as done.
 */
final class EntryService
{
    public const TYPE_TASK = 'task';

    private const RESOURCE = 'entry';

    public function __construct(private readonly MasterDataService $masterData)
    {
    }

    /**
     * Returns the entries from the start date to the end date (YYYY-MM-DD, inclusive).
     *
     * @return list<array<string, bool|int|string|list<int>>>
     */
    public function getByDateRange(int $memberId, string $start, string $end): array
    {
        return $this->masterData->findBy($memberId, self::RESOURCE, [['entryDate', '>=', $start], ['entryDate', '<=', $end]]);
    }

    /**
     * Returns the tasks before the given date that are not done.
     *
     * @return list<array<string, bool|int|string|list<int>>>
     */
    public function getOpen(int $memberId, string $before): array
    {
        return $this->masterData->findBy($memberId, self::RESOURCE, [['type', '=', self::TYPE_TASK], ['entryDate', '<', $before], ['done', '=', 0]]);
    }

    /**
     * Creates or updates an entry. Changing the date moves the entry to another day.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, bool|int|string|list<int>>
     *
     * @throws ValidationException
     * @throws RecordNotFoundException
     */
    public function save(int $memberId, int|null $id, array $input): array
    {
        // The time belongs to entries of the whole day only
        if (!\in_array($input['period'] ?? '', ['', 0, '0', null], true)) {
            $input['entryTime'] = '';
        }

        // Only tasks can be done
        if (self::TYPE_TASK !== ($input['type'] ?? null)) {
            $input['done'] = false;
        }

        // An entry is shown as important from a date on, only if it is important
        if (!\in_array($input['important'] ?? false, [true, 1, '1'], true)) {
            $input['visibleFrom'] = '';
        }

        return $this->masterData->save($memberId, self::RESOURCE, $id, $input);
    }

    /**
     * Marks a task as done or not done.
     *
     * @return array<string, bool|int|string|list<int>>
     *
     * @throws RecordNotFoundException if the entry does not exist or is not a task
     */
    public function setDone(int $memberId, int $id, bool $done): array
    {
        $entry = $this->masterData->get($memberId, self::RESOURCE, $id);

        if (self::TYPE_TASK !== $entry['type']) {
            throw new RecordNotFoundException();
        }

        return $this->masterData->save($memberId, self::RESOURCE, $id, [...$entry, 'done' => $done]);
    }

    /**
     * @throws RecordNotFoundException
     */
    public function delete(int $memberId, int $id): void
    {
        $this->masterData->delete($memberId, self::RESOURCE, $id);
    }
}
