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

use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;

/**
 * Builds the timetable of a week: the lessons of every day with their
 * subject and learning group. Days in the holidays have no lessons.
 */
final class TimetableService
{
    public function __construct(private readonly MasterDataService $masterData)
    {
    }

    /**
     * @param list<string> $dates The days of the week (YYYY-MM-DD)
     *
     * @return array{
     *     periods: list<array<string, int|string>>,
     *     subjects: array<int, array<string, int|string>>,
     *     groups: array<int, array<string, int|string>>,
     *     days: array<string, array{holiday: string|null, lessons: list<array<string, int|string>>}>
     * }
     */
    public function getWeek(int $memberId, array $dates): array
    {
        $schoolYears = $this->masterData->getList($memberId, 'schoolYear');
        $holidays = $this->masterData->getList($memberId, 'holiday');
        $lessons = $this->masterData->getList($memberId, 'lesson');

        $days = [];

        foreach ($dates as $date) {
            $holiday = $this->findByDate($holidays, $date);
            $schoolYear = $this->findByDate($schoolYears, $date);
            $weekday = (int) (new \DateTimeImmutable($date))->format('N');
            $dayLessons = [];

            if (null === $holiday && null !== $schoolYear) {
                foreach ($lessons as $lesson) {
                    if ($lesson['schoolYear'] === $schoolYear['id'] && $lesson['weekday'] === $weekday) {
                        $dayLessons[] = [
                            'id' => $lesson['id'],
                            'period' => $lesson['period'],
                            'subject' => $lesson['subject'],
                            'learningGroup' => $lesson['learningGroup'],
                            'room' => $lesson['room'],
                        ];
                    }
                }
            }

            $days[$date] = ['holiday' => null === $holiday ? null : (string) $holiday['title'], 'lessons' => $dayLessons];
        }

        return [
            'periods' => $this->masterData->getList($memberId, 'period'),
            'subjects' => array_column($this->masterData->getList($memberId, 'subject'), null, 'id'),
            'groups' => array_column($this->masterData->getList($memberId, 'group'), null, 'id'),
            'days' => $days,
        ];
    }

    /**
     * Returns the first record whose date range contains the date.
     *
     * @param list<array<string, int|string|list<int>>> $records
     *
     * @return array<string, int|string|list<int>>|null
     */
    private function findByDate(array $records, string $date): array|null
    {
        foreach ($records as $record) {
            if ($record['startDate'] <= $date && $date <= $record['endDate']) {
                return $record;
            }
        }

        return null;
    }
}
