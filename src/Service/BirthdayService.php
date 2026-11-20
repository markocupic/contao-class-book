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
 * The birthdays of the students in a date range.
 */
final class BirthdayService
{
    public function __construct(private readonly MasterDataService $masterData)
    {
    }

    /**
     * Returns the birthdays from the start date to the end date (YYYY-MM-DD, inclusive),
     * sorted by date and name. A birthday on February 29 is celebrated on February 28
     * in years without a leap day.
     *
     * @return list<array{student: int, firstname: string, lastname: string, homeClass: string, date: string, age: int}>
     */
    public function getBetween(int $memberId, string $start, string $end): array
    {
        $classes = array_column($this->masterData->getList($memberId, 'group'), 'title', 'id');
        $birthdays = [];

        foreach ($this->masterData->getList($memberId, 'student') as $student) {
            $dateOfBirth = (string) $student['dateOfBirth'];

            if ('' === $dateOfBirth) {
                continue;
            }

            // The range can span the turn of the year
            for ($year = (int) substr($start, 0, 4); $year <= (int) substr($end, 0, 4); ++$year) {
                $date = $this->getBirthday($dateOfBirth, $year);

                if ($date < $start || $date > $end || $date < $dateOfBirth) {
                    continue;
                }

                $birthdays[] = [
                    'student' => (int) $student['id'],
                    'firstname' => (string) $student['firstname'],
                    'lastname' => (string) $student['lastname'],
                    'homeClass' => (string) ($classes[$student['homeClass']] ?? ''),
                    'date' => $date,
                    'age' => $year - (int) substr($dateOfBirth, 0, 4),
                ];
            }
        }

        usort($birthdays, static fn (array $a, array $b): int => [$a['date'], $a['lastname'], $a['firstname']] <=> [$b['date'], $b['lastname'], $b['firstname']]);

        return $birthdays;
    }

    private function getBirthday(string $dateOfBirth, int $year): string
    {
        $monthAndDay = substr($dateOfBirth, 5);

        if ('02-29' === $monthAndDay && !checkdate(2, 29, $year)) {
            $monthAndDay = '02-28';
        }

        return \sprintf('%04d-%s', $year, $monthAndDay);
    }
}
