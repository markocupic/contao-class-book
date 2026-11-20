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

use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Service\TimetableService;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class TimetableServiceTest extends TestCase
{
    private const MEMBER = 1;

    private MasterDataService $masterData;

    private TimetableService $timetable;

    protected function setUp(): void
    {
        $this->masterData = new MasterDataService(DatabaseFactory::create(), new MasterDataValidator(), new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));
        $this->timetable = new TimetableService($this->masterData);

        // School year 2026/27 with the class 2a, two periods and two subjects
        $this->save('schoolYear', ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2027-07-02']);
        $this->save('group', ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);
        $this->save('period', ['number' => 2, 'startTime' => '08:20', 'endTime' => '09:05']);
        $this->save('period', ['number' => 1, 'startTime' => '07:30', 'endTime' => '08:15']);
        $this->save('subject', ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#2563eb']);
        $this->save('subject', ['title' => 'Deutsch', 'shortTitle' => 'DE', 'color' => '#dc2626']);

        // Two lessons on Monday, one lesson on Friday
        $this->save('lesson', ['schoolYear' => 1, 'weekday' => 1, 'period' => 1, 'subject' => 2, 'learningGroup' => 1, 'room' => '12']);
        $this->save('lesson', ['schoolYear' => 1, 'weekday' => 1, 'period' => 2, 'subject' => 1, 'learningGroup' => 1]);
        $this->save('lesson', ['schoolYear' => 1, 'weekday' => 5, 'period' => 2, 'subject' => 1, 'learningGroup' => 1]);
    }

    public function testReturnsTheLessonsOfTheWeek(): void
    {
        $week = $this->timetable->getWeek(self::MEMBER, ['2026-11-16', '2026-11-17', '2026-11-20']);

        $this->assertSame([1, 2], array_column($week['periods'], 'number'));
        $this->assertSame('Mathematik', $week['subjects'][1]['title']);
        $this->assertSame('2a', $week['groups'][1]['title']);
        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-20'], array_keys($week['days']));

        $this->assertSame(
            [
                'holiday' => null,
                'lessons' => [
                    ['id' => 1, 'period' => 1, 'subject' => 2, 'learningGroup' => 1, 'room' => '12'],
                    ['id' => 2, 'period' => 2, 'subject' => 1, 'learningGroup' => 1, 'room' => ''],
                ],
            ],
            $week['days']['2026-11-16'],
        );

        $this->assertSame(['holiday' => null, 'lessons' => []], $week['days']['2026-11-17']);
        $this->assertSame([3], array_column($week['days']['2026-11-20']['lessons'], 'id'));
    }

    public function testThereAreNoLessonsInTheHolidays(): void
    {
        $this->save('holiday', ['title' => 'Weihnachtsferien', 'startDate' => '2026-12-19', 'endDate' => '2027-01-03']);

        $week = $this->timetable->getWeek(self::MEMBER, ['2026-12-18', '2026-12-21', '2027-01-04']);

        $this->assertSame([3], array_column($week['days']['2026-12-18']['lessons'], 'id'));
        $this->assertSame(['holiday' => 'Weihnachtsferien', 'lessons' => []], $week['days']['2026-12-21']);
        $this->assertSame([1, 2], array_column($week['days']['2027-01-04']['lessons'], 'id'));
    }

    public function testThereAreNoLessonsOutsideOfTheSchoolYear(): void
    {
        $week = $this->timetable->getWeek(self::MEMBER, ['2026-08-10', '2026-08-17', '2027-07-05']);

        $this->assertSame([], $week['days']['2026-08-10']['lessons']);
        $this->assertSame([1, 2], array_column($week['days']['2026-08-17']['lessons'], 'id'));
        $this->assertSame([], $week['days']['2027-07-05']['lessons']);
    }

    public function testEveryMemberHasAnOwnTimetable(): void
    {
        $week = $this->timetable->getWeek(2, ['2026-11-16']);

        $this->assertSame(['periods' => [], 'subjects' => [], 'groups' => [], 'days' => ['2026-11-16' => ['holiday' => null, 'lessons' => []]]], $week);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(string $resource, array $data): void
    {
        $this->masterData->save(self::MEMBER, $resource, null, $data);
    }
}
