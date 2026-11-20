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

/**
 * Calculates the calendar week (Monday to Sunday) a date belongs to.
 */
final class WeekCalculator
{
    public const DATE_FORMAT = 'Y-m-d';

    /**
     * Parses a date in the format YYYY-MM-DD. Returns null if the date is invalid.
     */
    public function parseDate(string $date): \DateTimeImmutable|null
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $date);

        if (false === $parsed || $parsed->format(self::DATE_FORMAT) !== $date) {
            return null;
        }

        return $parsed;
    }

    /**
     * @return array{year: int, week: int, start: string, end: string, previous: string, next: string, today: string, days: list<array{date: string, weekday: int, isToday: bool}>}
     */
    public function getWeek(\DateTimeImmutable $date, \DateTimeImmutable $today): array
    {
        $monday = $date->setTime(0, 0)->modify('-'.((int) $date->format('N') - 1).' days');
        $todayDate = $today->format(self::DATE_FORMAT);
        $days = [];

        for ($i = 0; $i < 7; ++$i) {
            $day = $monday->modify('+'.$i.' days');

            $days[] = [
                'date' => $day->format(self::DATE_FORMAT),
                'weekday' => $i + 1,
                'isToday' => $day->format(self::DATE_FORMAT) === $todayDate,
            ];
        }

        return [
            'year' => (int) $monday->format('o'),
            'week' => (int) $monday->format('W'),
            'start' => $days[0]['date'],
            'end' => $days[6]['date'],
            'previous' => $monday->modify('-7 days')->format(self::DATE_FORMAT),
            'next' => $monday->modify('+7 days')->format(self::DATE_FORMAT),
            'today' => $todayDate,
            'days' => $days,
        ];
    }
}
