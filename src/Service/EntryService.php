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

    public const TYPE_APPOINTMENT = 'appointment';

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
     * Returns the important entries that are visible on the given date: the entry
     * is today or in the future, its "visible from" date has been reached and,
     * if it is a task, it is not done.
     *
     * @return list<array<string, bool|int|string|list<int>>>
     */
    public function getImportant(int $memberId, string $today): array
    {
        $entries = $this->masterData->findBy($memberId, self::RESOURCE, [['important', '=', 1], ['entryDate', '>=', $today]]);

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['visibleFrom'] <= $today && !(self::TYPE_TASK === $entry['type'] && $entry['done']),
        ));
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

        // Only appointments have a location
        if (self::TYPE_APPOINTMENT !== ($input['type'] ?? null)) {
            $input['location'] = '';
        }

        // An entry is shown as important from a date on, only if it is important
        if (!\in_array($input['important'] ?? false, [true, 1, '1'], true)) {
            $input['visibleFrom'] = '';
        }

        // New entries and entries that change their day or period go to the end of their slot
        $current = null === $id ? null : $this->masterData->get($memberId, self::RESOURCE, $id);
        $date = \is_string($input['entryDate'] ?? null) ? $input['entryDate'] : '';
        $period = is_numeric($input['period'] ?? null) ? (int) $input['period'] : 0;

        if (null !== $current && $current['entryDate'] === $date && $current['period'] === $period) {
            $input['sorting'] = $current['sorting'];
        } else {
            $input['sorting'] = $this->getNextSorting($memberId, $date, $period);
        }

        // A reminder is sent once. It is sent again if the entry gets another time or another reminder
        $unchanged = null !== $current
            && $current['entryDate'] === $date
            && $current['period'] === $period
            && $current['entryTime'] === trim((string) ($input['entryTime'] ?? ''))
            && $current['reminder'] === trim((string) ($input['reminder'] ?? ''));

        $input['reminderSent'] = $unchanged && $current['reminderSent'];

        return $this->masterData->save($memberId, self::RESOURCE, $id, $input);
    }

    /**
     * Moves an entry to a position (0 = first) within a period or the whole day
     * (period 0) of a date. This changes the order within a slot as well as the
     * slot or the day of the entry.
     *
     * @return array<string, bool|int|string|list<int>>
     *
     * @throws ValidationException
     * @throws RecordNotFoundException
     */
    public function move(int $memberId, int $id, string $date, int $period, int $position): array
    {
        $entry = $this->masterData->get($memberId, self::RESOURCE, $id);

        // Validates the date and the period, the entry goes to the end of the slot
        $entry = $this->save($memberId, $id, [...$entry, 'entryDate' => $date, 'period' => $period]);

        $others = array_values(array_filter(
            $this->getSlot($memberId, $date, $period),
            static fn (array $other): bool => $other['id'] !== $id,
        ));

        array_splice($others, max(0, min($position, \count($others))), 0, [$entry]);

        foreach ($others as $index => $other) {
            if ($other['sorting'] !== $index + 1) {
                $this->masterData->save($memberId, self::RESOURCE, $other['id'], [...$other, 'sorting' => $index + 1]);
            }
        }

        return $this->masterData->get($memberId, self::RESOURCE, $id);
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

    /**
     * Copies an entry. The copy is placed directly below the original.
     * The copy of a task is not done.
     *
     * @return array<string, bool|int|string|list<int>>
     *
     * @throws RecordNotFoundException
     */
    public function copy(int $memberId, int $id): array
    {
        $entry = $this->masterData->get($memberId, self::RESOURCE, $id);
        $date = (string) $entry['entryDate'];
        $period = (int) $entry['period'];

        $copy = $this->save($memberId, null, [...$entry, 'done' => false]);
        $position = array_search($id, array_column($this->getSlot($memberId, $date, $period), 'id'), true);

        return $this->move($memberId, (int) $copy['id'], $date, $period, (int) $position + 1);
    }

    /**
     * Returns the entries of a period or the whole day (period 0) in their order.
     *
     * @return list<array<string, bool|int|string|list<int>>>
     */
    private function getSlot(int $memberId, string $date, int $period): array
    {
        return $this->masterData->findBy($memberId, self::RESOURCE, [['entryDate', '=', $date], ['period', '=', $period]]);
    }

    private function getNextSorting(int $memberId, string $date, int $period): int
    {
        $sorting = array_column($this->getSlot($memberId, $date, $period), 'sorting');

        return [] === $sorting ? 1 : max($sorting) + 1;
    }
}
