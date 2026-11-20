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

namespace Markocupic\ContaoClassBook\Tests\Service\MasterData;

use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Exception\RecordInUseException;
use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class MasterDataServiceTest extends TestCase
{
    private const MEMBER = 1;

    private const OTHER_MEMBER = 2;

    private Connection $connection;

    private MasterDataService $service;

    protected function setUp(): void
    {
        $this->connection = DatabaseFactory::create();

        $this->service = new MasterDataService(
            $this->connection,
            new MasterDataValidator(),
            new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')),
        );
    }

    public function testCreatesAndUpdatesARecord(): void
    {
        $record = $this->service->save(self::MEMBER, 'subject', null, ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#2563eb']);

        $this->assertSame(['id' => 1, 'title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#2563eb'], $record);

        $record = $this->service->save(self::MEMBER, 'subject', 1, ['title' => 'Mathe', 'shortTitle' => 'M', 'color' => '#475569']);

        $this->assertSame(['id' => 1, 'title' => 'Mathe', 'shortTitle' => 'M', 'color' => '#475569'], $record);
        $this->assertSame([$record], $this->service->getList(self::MEMBER, 'subject'));

        $row = $this->connection->fetchAssociative('SELECT member, tstamp FROM tl_cb_subject WHERE id = 1');

        $this->assertSame(self::MEMBER, (int) $row['member']);
        $this->assertSame((new \DateTimeImmutable('2026-11-20 08:00:00'))->getTimestamp(), (int) $row['tstamp']);
    }

    public function testStoresStringsEncodedAndReturnsThemDecoded(): void
    {
        $this->service->save(self::MEMBER, 'subject', null, ['title' => 'Medien & <b>Informatik</b>', 'shortTitle' => 'M&I', 'color' => '#475569']);

        $this->assertSame('Medien &amp; &lt;b&gt;Informatik&lt;/b&gt;', $this->connection->fetchOne('SELECT title FROM tl_cb_subject'));
        $this->assertSame('Medien & <b>Informatik</b>', $this->service->getList(self::MEMBER, 'subject')[0]['title']);
    }

    public function testReturnsTheRecordsOfTheMemberInTheDefinedOrder(): void
    {
        $this->service->save(self::MEMBER, 'period', null, ['number' => 2, 'startTime' => '08:20', 'endTime' => '09:05']);
        $this->service->save(self::MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:30', 'endTime' => '08:15']);
        $this->service->save(self::OTHER_MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:00', 'endTime' => '07:45']);

        $data = $this->service->getAll(self::MEMBER);

        $this->assertSame(['schoolYear', 'holiday', 'period', 'subject', 'classroom', 'group', 'student', 'lesson'], array_keys($data));
        $this->assertSame([1, 2], array_column($data['period'], 'number'));
        $this->assertSame(['07:00'], array_column($this->service->getList(self::OTHER_MEMBER, 'period'), 'startTime'));
    }

    public function testDoesNotSaveInvalidData(): void
    {
        try {
            $this->service->save(self::MEMBER, 'subject', null, ['title' => '', 'shortTitle' => 'MA', 'color' => '#475569']);

            $this->fail('The data should be invalid.');
        } catch (ValidationException $exception) {
            $this->assertSame(['title' => 'required'], $exception->getErrors());
        }

        $this->assertSame([], $this->service->getList(self::MEMBER, 'subject'));
    }

    public function testCannotUpdateTheRecordOfAnotherMember(): void
    {
        $this->service->save(self::OTHER_MEMBER, 'subject', null, ['title' => 'Deutsch', 'shortTitle' => 'DE', 'color' => '#475569']);

        $this->expectException(RecordNotFoundException::class);

        $this->service->save(self::MEMBER, 'subject', 1, ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#475569']);
    }

    public function testCannotDeleteTheRecordOfAnotherMember(): void
    {
        $this->service->save(self::OTHER_MEMBER, 'subject', null, ['title' => 'Deutsch', 'shortTitle' => 'DE', 'color' => '#475569']);

        try {
            $this->service->delete(self::MEMBER, 'subject', 1);

            $this->fail('The record should not be found.');
        } catch (RecordNotFoundException) {
            $this->assertCount(1, $this->service->getList(self::OTHER_MEMBER, 'subject'));
        }
    }

    public function testCannotReferenceTheRecordOfAnotherMember(): void
    {
        $this->service->save(self::OTHER_MEMBER, 'schoolYear', null, ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2027-07-02']);

        try {
            $this->service->save(self::MEMBER, 'group', null, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);

            $this->fail('The reference should be unknown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['schoolYear' => 'unknownReference'], $exception->getErrors());
        }
    }

    public function testAStudentHasOneHomeClassAndAnyNumberOfLearningGroups(): void
    {
        $this->createGroups();

        $record = $this->service->save(self::MEMBER, 'student', null, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'dateOfBirth' => '2013-05-04', 'gender' => 'f', 'homeClass' => 1, 'learningGroups' => [4, '3', 4]]);

        $this->assertSame(['id' => 1, 'lastname' => 'Muster', 'firstname' => 'Zoe', 'dateOfBirth' => '2013-05-04', 'gender' => 'f', 'homeClass' => 1, 'learningGroups' => [3, 4]], $record);

        // The date of birth, the gender and the learning groups are optional
        $this->service->save(self::MEMBER, 'student', null, ['lastname' => 'Beispiel', 'firstname' => 'Tim', 'homeClass' => 2]);

        $this->assertSame(
            [
                ['id' => 2, 'lastname' => 'Beispiel', 'firstname' => 'Tim', 'dateOfBirth' => '', 'gender' => '', 'homeClass' => 2, 'learningGroups' => []],
                $record,
            ],
            $this->service->getList(self::MEMBER, 'student'),
        );

        // Updating replaces the learning groups
        $this->service->save(self::MEMBER, 'student', 1, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'homeClass' => 2, 'learningGroups' => [4]]);

        $this->assertSame([4], $this->service->getList(self::MEMBER, 'student')[1]['learningGroups']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_cb_student_group'));
    }

    public function testTheHomeClassHasToBeAClassAndTheLearningGroupsMustNotBeClasses(): void
    {
        $this->createGroups();

        $this->assertSame(
            ['homeClass' => 'unknownReference'],
            $this->getErrors('student', null, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'homeClass' => 3]),
        );

        $this->assertSame(
            ['learningGroups' => 'unknownReference'],
            $this->getErrors('student', null, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'homeClass' => 1, 'learningGroups' => [3, 2]]),
        );

        $this->assertSame(
            ['homeClass' => 'required'],
            $this->getErrors('student', null, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'learningGroups' => [3]]),
        );

        $this->assertSame([], $this->service->getList(self::MEMBER, 'student'));
    }

    public function testGroupsWithStudentsCannotBeDeletedOrChangeTheirKind(): void
    {
        $this->createGroups();

        $this->service->save(self::MEMBER, 'student', null, ['lastname' => 'Muster', 'firstname' => 'Zoe', 'homeClass' => 1, 'learningGroups' => [3]]);

        foreach ([1, 3] as $id) {
            try {
                $this->service->delete(self::MEMBER, 'group', $id);

                $this->fail('The group should be in use.');
            } catch (RecordInUseException) {
                $this->assertCount(4, $this->service->getList(self::MEMBER, 'group'));
            }
        }

        $this->assertSame(['kind' => 'locked'], $this->getErrors('group', 1, ['title' => '2a', 'kind' => 'group', 'schoolYear' => 1]));
        $this->assertSame(['kind' => 'locked'], $this->getErrors('group', 3, ['title' => 'Mathematik Niveau A', 'kind' => 'class', 'schoolYear' => 1]));

        // Other fields can still be changed, unused groups can change their kind
        $this->service->save(self::MEMBER, 'group', 1, ['title' => '2A', 'kind' => 'class', 'schoolYear' => 1]);
        $this->service->save(self::MEMBER, 'group', 4, ['title' => 'Chor', 'kind' => 'class', 'schoolYear' => 1]);

        // Deleting the student removes the links and releases the groups
        $this->service->delete(self::MEMBER, 'student', 1);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_cb_student_group'));

        $this->service->delete(self::MEMBER, 'group', 3);
        $this->service->delete(self::MEMBER, 'group', 1);

        $this->assertCount(2, $this->service->getList(self::MEMBER, 'group'));
    }

    public function testOnlyOneLessonPerSchoolYearWeekdayAndPeriod(): void
    {
        $this->createGroups();

        $this->service->save(self::MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:30', 'endTime' => '08:15']);
        $this->service->save(self::MEMBER, 'subject', null, ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#2563eb']);

        $lesson = ['schoolYear' => 1, 'weekday' => 1, 'period' => 1, 'subject' => 1, 'learningGroup' => 1, 'room' => '12'];

        $this->service->save(self::MEMBER, 'lesson', null, $lesson);

        $this->assertSame(['period' => 'notUnique'], $this->getErrors('lesson', null, [...$lesson, 'learningGroup' => 3]));

        // Another weekday is free, and the lesson itself can be updated
        $this->service->save(self::MEMBER, 'lesson', null, [...$lesson, 'weekday' => 2]);
        $this->service->save(self::MEMBER, 'lesson', 1, [...$lesson, 'room' => '14']);

        $this->assertSame(['14', '12'], array_column($this->service->getList(self::MEMBER, 'lesson'), 'room'));
        $this->assertSame(['period' => 'notUnique'], $this->getErrors('lesson', 2, $lesson));

        // The subject is in use now
        $this->expectException(RecordInUseException::class);

        $this->service->delete(self::MEMBER, 'subject', 1);
    }

    public function testAGroupHasAClassroomAndASeatingPlan(): void
    {
        $this->createGroups();

        // JSON is stored as it is, not HTML encoded
        $layout = '{"desks":[{"id":"a","type":"double","x":2,"y":4,"r":0}]}';
        $seating = '{"seats":{"a:0":7,"a:1":9},"blocked":[]}';

        $classroom = $this->service->save(self::MEMBER, 'classroom', null, ['title' => 'Zimmer 12', 'layout' => $layout]);

        $this->assertSame(['id' => 1, 'title' => 'Zimmer 12', 'layout' => $layout], $classroom);
        $this->assertSame($layout, $this->connection->fetchOne('SELECT layout FROM tl_cb_classroom WHERE id = 1'));

        $group = $this->service->save(self::MEMBER, 'group', 1, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1, 'classroom' => 1, 'seating' => $seating]);

        $this->assertSame(['id' => 1, 'title' => '2a', 'kind' => 'class', 'schoolYear' => 1, 'classroom' => 1, 'seating' => $seating], $group);

        // The classroom of another member is unknown
        $this->service->save(self::OTHER_MEMBER, 'classroom', null, ['title' => 'Aula']);

        $this->assertSame(['classroom' => 'unknownReference'], $this->getErrors('group', 2, ['title' => '2b', 'kind' => 'class', 'schoolYear' => 1, 'classroom' => 2]));

        // A classroom cannot be deleted as long as a group uses it
        try {
            $this->service->delete(self::MEMBER, 'classroom', 1);

            $this->fail('The classroom should be in use.');
        } catch (RecordInUseException) {
            $this->assertCount(1, $this->service->getList(self::MEMBER, 'classroom'));
        }

        $this->service->save(self::MEMBER, 'group', 1, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);
        $this->service->delete(self::MEMBER, 'classroom', 1);

        $this->assertSame([], $this->service->getList(self::MEMBER, 'classroom'));
    }

    public function testDeletesARecord(): void
    {
        $this->service->save(self::MEMBER, 'holiday', null, ['title' => 'Weihnachtsferien', 'startDate' => '2026-12-19', 'endDate' => '2027-01-03']);
        $this->service->delete(self::MEMBER, 'holiday', 1);

        $this->assertSame([], $this->service->getList(self::MEMBER, 'holiday'));
    }

    public function testDoesNotDeleteARecordThatIsInUse(): void
    {
        $this->service->save(self::MEMBER, 'schoolYear', null, ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2027-07-02']);
        $this->service->save(self::MEMBER, 'group', null, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);

        try {
            $this->service->delete(self::MEMBER, 'schoolYear', 1);

            $this->fail('The record should be in use.');
        } catch (RecordInUseException) {
            $this->assertCount(1, $this->service->getList(self::MEMBER, 'schoolYear'));
        }

        $this->service->delete(self::MEMBER, 'group', 1);
        $this->service->delete(self::MEMBER, 'schoolYear', 1);

        $this->assertSame([], $this->service->getList(self::MEMBER, 'schoolYear'));
    }

    /**
     * Creates the classes 2a (1) and 2b (2) and the learning groups
     * "Mathematik Niveau A" (3) and "Chor" (4).
     */
    private function createGroups(): void
    {
        $this->service->save(self::MEMBER, 'schoolYear', null, ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2027-07-02']);
        $this->service->save(self::MEMBER, 'group', null, ['title' => '2a', 'kind' => 'class', 'schoolYear' => 1]);
        $this->service->save(self::MEMBER, 'group', null, ['title' => '2b', 'kind' => 'class', 'schoolYear' => 1]);
        $this->service->save(self::MEMBER, 'group', null, ['title' => 'Mathematik Niveau A', 'kind' => 'group', 'schoolYear' => 1]);
        $this->service->save(self::MEMBER, 'group', null, ['title' => 'Chor', 'kind' => 'group', 'schoolYear' => 1]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function getErrors(string $resource, int|null $id, array $input): array
    {
        try {
            $this->service->save(self::MEMBER, $resource, $id, $input);
        } catch (ValidationException $exception) {
            return $exception->getErrors();
        }

        $this->fail('The data should be invalid.');
    }
}
