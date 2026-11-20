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

use Markocupic\ContaoClassBook\Service\WeekCalculator;
use PHPUnit\Framework\TestCase;

class WeekCalculatorTest extends TestCase
{
    public function testReturnsTheWeekFromMondayToSunday(): void
    {
        $calculator = new WeekCalculator();
        $week = $calculator->getWeek(new \DateTimeImmutable('2026-11-20 18:00:00'), new \DateTimeImmutable('2026-11-20 18:00:00'));

        $this->assertSame(2026, $week['year']);
        $this->assertSame(47, $week['week']);
        $this->assertSame('2026-11-16', $week['start']);
        $this->assertSame('2026-11-22', $week['end']);
        $this->assertSame('2026-11-09', $week['previous']);
        $this->assertSame('2026-11-23', $week['next']);
        $this->assertSame('2026-11-20', $week['today']);
        $this->assertCount(7, $week['days']);
        $this->assertSame(['date' => '2026-11-16', 'weekday' => 1, 'isToday' => false], $week['days'][0]);
        $this->assertSame(['date' => '2026-11-20', 'weekday' => 5, 'isToday' => true], $week['days'][4]);
    }

    public function testASundayBelongsToTheWeekThatStartedOnMonday(): void
    {
        $week = (new WeekCalculator())->getWeek(new \DateTimeImmutable('2026-11-22'), new \DateTimeImmutable('2026-11-20'));

        $this->assertSame('2026-11-16', $week['start']);
    }

    public function testUsesTheIsoYearAtTheTurnOfTheYear(): void
    {
        $week = (new WeekCalculator())->getWeek(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2026-11-20'));

        $this->assertSame(2026, $week['year']);
        $this->assertSame(53, $week['week']);
        $this->assertSame('2026-12-28', $week['start']);
        $this->assertSame('2027-01-03', $week['end']);
    }

    public function testParsesValidDatesOnly(): void
    {
        $calculator = new WeekCalculator();

        $this->assertSame('2026-11-20', $calculator->parseDate('2026-11-20')?->format('Y-m-d'));
        $this->assertNull($calculator->parseDate('2026-02-30'));
        $this->assertNull($calculator->parseDate('20.11.2026'));
        $this->assertNull($calculator->parseDate(''));
    }
}
