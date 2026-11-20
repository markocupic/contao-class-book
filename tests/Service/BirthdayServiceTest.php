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

use Markocupic\ContaoClassBook\Service\BirthdayService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class BirthdayServiceTest extends TestCase
{
    private const MEMBER = 1;

    private MasterDataService $masterData;

    private BirthdayService $birthdays;

    protected function setUp(): void
    {
        $this->masterData = new MasterDataService(DatabaseFactory::create(), new MasterDataValidator(), new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));
        $this->birthdays = new BirthdayService($this->masterData);

        $this->masterData->save(self::MEMBER, 'schoolYear', null, ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2027-07-02']);
        $this->masterData->save(self::MEMBER, 'group', null, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);

        $this->addStudent('Muster', 'Zoe', '2013-11-22');
        $this->addStudent('Beispiel', 'Tim', '2013-11-22');
        $this->addStudent('Winter', 'Lea', '2014-01-02');
        $this->addStudent('Schalt', 'Jan', '2012-02-29');
        $this->addStudent('Ohne', 'Datum', '');
    }

    public function testReturnsTheBirthdaysOfADateRangeSortedByDateAndName(): void
    {
        $birthdays = $this->birthdays->getBetween(self::MEMBER, '2026-11-20', '2026-11-27');

        $this->assertSame(
            [
                ['student' => 2, 'firstname' => 'Tim', 'lastname' => 'Beispiel', 'homeClass' => '2a', 'date' => '2026-11-22', 'age' => 13],
                ['student' => 1, 'firstname' => 'Zoe', 'lastname' => 'Muster', 'homeClass' => '2a', 'date' => '2026-11-22', 'age' => 13],
            ],
            $birthdays,
        );
    }

    public function testTheRangeIsInclusive(): void
    {
        $this->assertCount(2, $this->birthdays->getBetween(self::MEMBER, '2026-11-22', '2026-11-22'));
        $this->assertSame([], $this->birthdays->getBetween(self::MEMBER, '2026-11-23', '2026-11-30'));
    }

    public function testTheRangeCanSpanTheTurnOfTheYear(): void
    {
        $birthdays = $this->birthdays->getBetween(self::MEMBER, '2026-12-28', '2027-01-03');

        $this->assertSame([['Lea', '2027-01-02', 13]], array_map(static fn (array $birthday): array => [$birthday['firstname'], $birthday['date'], $birthday['age']], $birthdays));
    }

    public function testALeapDayBirthdayIsOnFebruary28InOtherYears(): void
    {
        $this->assertSame(['2027-02-28'], array_column($this->birthdays->getBetween(self::MEMBER, '2027-02-22', '2027-03-01'), 'date'));
        $this->assertSame(['2028-02-29'], array_column($this->birthdays->getBetween(self::MEMBER, '2028-02-22', '2028-03-01'), 'date'));
    }

    public function testEveryMemberHasOwnStudents(): void
    {
        $this->assertSame([], $this->birthdays->getBetween(2, '2026-01-01', '2026-12-31'));
    }

    private function addStudent(string $lastname, string $firstname, string $dateOfBirth): void
    {
        $this->masterData->save(self::MEMBER, 'student', null, ['lastname' => $lastname, 'firstname' => $firstname, 'dateOfBirth' => $dateOfBirth, 'homeClass' => 1]);
    }
}
