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

use Markocupic\ContaoClassBook\Exception\RecordInUseException;
use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Service\EntryService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class EntryServiceTest extends TestCase
{
    private const MEMBER = 1;

    private const OTHER_MEMBER = 2;

    private MasterDataService $masterData;

    private EntryService $entries;

    protected function setUp(): void
    {
        $this->masterData = new MasterDataService(DatabaseFactory::create(), new MasterDataValidator(), new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')));
        $this->entries = new EntryService($this->masterData);

        $this->masterData->save(self::MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:30', 'endTime' => '08:15']);
    }

    public function testCreatesAnEntryForAPeriod(): void
    {
        $entry = $this->entries->save(self::MEMBER, null, ['type' => 'remark', 'entryDate' => '2026-11-20', 'period' => 1, 'entryTime' => '09:00', 'content' => "Kapitel 3 <fertig>\nSeite 12"]);

        $this->assertSame(
            ['id' => 1, 'type' => 'remark', 'entryDate' => '2026-11-20', 'period' => 1, 'entryTime' => '', 'content' => "Kapitel 3 <fertig>\nSeite 12", 'done' => false, 'important' => false, 'visibleFrom' => '', 'location' => '', 'sorting' => 1, 'reminder' => '', 'reminderSent' => false],
            $entry,
        );
    }

    public function testCreatesAnEntryForTheDayWithAnOptionalTime(): void
    {
        $entry = $this->entries->save(self::MEMBER, null, ['type' => 'appointment', 'entryDate' => '2026-11-20', 'entryTime' => '16:30', 'content' => 'Elterngespräch', 'important' => true, 'visibleFrom' => '2026-11-13']);

        $this->assertSame(0, $entry['period']);
        $this->assertSame('16:30', $entry['entryTime']);
        $this->assertTrue($entry['important']);
        $this->assertSame('2026-11-13', $entry['visibleFrom']);

        // "Visible from" is dropped if the entry is not important
        $entry = $this->entries->save(self::MEMBER, 1, [...$entry, 'important' => false]);

        $this->assertSame('', $entry['visibleFrom']);
    }

    public function testOnlyAnAppointmentHasALocation(): void
    {
        $entry = $this->entries->save(self::MEMBER, null, ['type' => 'appointment', 'entryDate' => '2026-11-27', 'entryTime' => '16:30', 'content' => 'Elterngespräch', 'location' => 'Zimmer 12 & Aula']);

        $this->assertSame('Zimmer 12 & Aula', $entry['location']);

        $entry = $this->entries->save(self::MEMBER, 1, [...$entry, 'type' => 'note']);

        $this->assertSame('', $entry['location']);
    }

    public function testAnExamIsAnEntryWithoutACheckbox(): void
    {
        $entry = $this->entries->save(self::MEMBER, null, ['type' => 'exam', 'entryDate' => '2026-11-27', 'period' => 1, 'content' => 'Prüfung Bruchrechnen', 'done' => true]);

        $this->assertSame('exam', $entry['type']);
        $this->assertFalse($entry['done']);
    }

    public function testValidatesTheEntry(): void
    {
        try {
            $this->entries->save(self::MEMBER, null, ['type' => 'foo', 'entryDate' => '20.11.2026', 'period' => 9, 'content' => ' ']);

            $this->fail('The entry should be invalid.');
        } catch (ValidationException $exception) {
            $this->assertSame(['type' => 'invalid', 'entryDate' => 'invalid', 'period' => 'unknownReference', 'content' => 'required'], $exception->getErrors());
        }
    }

    public function testReturnsTheEntriesOfADateRange(): void
    {
        $this->create('2026-11-15', 'before');
        $this->create('2026-11-16', 'monday');
        $this->create('2026-11-22', 'sunday');
        $this->create('2026-11-23', 'after');

        $this->entries->save(self::OTHER_MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-18', 'content' => 'other member']);

        $this->assertSame(['monday', 'sunday'], array_column($this->entries->getByDateRange(self::MEMBER, '2026-11-16', '2026-11-22'), 'content'));
    }

    public function testMarksAnEntryAsDone(): void
    {
        $this->create('2026-11-18', 'one');

        $this->assertTrue($this->entries->setDone(self::MEMBER, 1, true)['done']);
        $this->assertFalse($this->entries->setDone(self::MEMBER, 1, false)['done']);

        $this->expectException(RecordNotFoundException::class);

        $this->entries->setDone(self::OTHER_MEMBER, 1, true);
    }

    public function testOnlyTasksCanBeDone(): void
    {
        $note = $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-18', 'content' => 'note', 'done' => true]);

        $this->assertFalse($note['done']);

        $task = $this->entries->save(self::MEMBER, null, ['type' => 'task', 'entryDate' => '2026-11-18', 'content' => 'task', 'done' => true]);

        $this->assertTrue($task['done']);

        // Turning a task into a note resets the state
        $this->assertFalse($this->entries->save(self::MEMBER, 2, [...$task, 'type' => 'note'])['done']);

        // Only tasks are reported as open
        $this->assertSame([], $this->entries->getOpen(self::MEMBER, '2026-11-20'));

        $this->expectException(RecordNotFoundException::class);

        $this->entries->setDone(self::MEMBER, 1, true);
    }

    public function testReturnsTheOpenTasksBeforeADate(): void
    {
        $this->create('2026-11-17', 'open');
        $this->create('2026-11-18', 'done');
        $this->create('2026-11-20', 'today');
        $this->create('2026-11-17', 'remark', 'remark');

        $this->entries->setDone(self::MEMBER, 2, true);

        $this->assertSame(['open'], array_column($this->entries->getOpen(self::MEMBER, '2026-11-20'), 'content'));

        // Moving the entry to a later day
        $entry = $this->entries->getOpen(self::MEMBER, '2026-11-20')[0];
        $this->entries->save(self::MEMBER, $entry['id'], [...$entry, 'entryDate' => '2026-11-24']);

        $this->assertSame([], $this->entries->getOpen(self::MEMBER, '2026-11-20'));
        $this->assertSame(['open'], array_column($this->entries->getByDateRange(self::MEMBER, '2026-11-24', '2026-11-24'), 'content'));
    }

    public function testReturnsTheImportantEntriesThatAreVisible(): void
    {
        $entry = ['type' => 'appointment', 'important' => true];

        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-11-19', 'content' => 'past']);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-12-18', 'content' => 'visible later', 'visibleFrom' => '2026-11-21']);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-12-10', 'content' => 'visible', 'visibleFrom' => '2026-11-20']);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-11-20', 'content' => 'today']);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-11-25', 'content' => 'not important', 'important' => false]);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-11-24', 'content' => 'done task', 'type' => 'task', 'done' => true]);
        $this->entries->save(self::MEMBER, null, [...$entry, 'entryDate' => '2026-11-23', 'content' => 'open task', 'type' => 'task']);
        $this->entries->save(self::OTHER_MEMBER, null, [...$entry, 'entryDate' => '2026-11-22', 'content' => 'other member']);

        $this->assertSame(['today', 'open task', 'visible'], array_column($this->entries->getImportant(self::MEMBER, '2026-11-20'), 'content'));
        $this->assertSame(['open task', 'visible', 'visible later'], array_column($this->entries->getImportant(self::MEMBER, '2026-11-21'), 'content'));
    }

    public function testNewEntriesGoToTheEndOfTheirSlot(): void
    {
        $this->create('2026-11-18', 'one');
        $this->create('2026-11-18', 'two');

        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-18', 'period' => 1, 'content' => 'period']);

        $this->assertSame([['one', 1], ['two', 2], ['period', 1]], $this->getOrder('2026-11-18'));

        // Editing an entry keeps its position, whatever the submitted sorting is
        $entry = $this->entries->getByDateRange(self::MEMBER, '2026-11-18', '2026-11-18')[0];
        $this->entries->save(self::MEMBER, $entry['id'], [...$entry, 'content' => 'one changed', 'sorting' => 99]);

        $this->assertSame([['one changed', 1], ['two', 2], ['period', 1]], $this->getOrder('2026-11-18'));

        // Changing the period in the form appends the entry to the other slot
        $this->entries->save(self::MEMBER, $entry['id'], [...$entry, 'period' => 1]);

        $this->assertSame([['two', 2], ['period', 1], ['one', 2]], $this->getOrder('2026-11-18'));
    }

    public function testMovesAnEntryWithinItsSlot(): void
    {
        $this->create('2026-11-18', 'one');
        $this->create('2026-11-18', 'two');
        $this->create('2026-11-18', 'three');

        $this->entries->move(self::MEMBER, 3, '2026-11-18', 0, 0);

        $this->assertSame([['three', 1], ['one', 2], ['two', 3]], $this->getOrder('2026-11-18'));

        $this->entries->move(self::MEMBER, 3, '2026-11-18', 0, 1);

        $this->assertSame([['one', 1], ['three', 2], ['two', 3]], $this->getOrder('2026-11-18'));

        // A position behind the end means the end
        $this->entries->move(self::MEMBER, 1, '2026-11-18', 0, 99);

        $this->assertSame([['three', 1], ['two', 2], ['one', 3]], $this->getOrder('2026-11-18'));
    }

    public function testMovesAnEntryToAnotherSlotOrDay(): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'appointment', 'entryDate' => '2026-11-18', 'entryTime' => '16:30', 'content' => 'one']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-19', 'period' => 1, 'content' => 'two']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-19', 'period' => 1, 'content' => 'three']);

        $entry = $this->entries->move(self::MEMBER, 1, '2026-11-19', 1, 1);

        $this->assertSame(['2026-11-19', 1, ''], [$entry['entryDate'], $entry['period'], $entry['entryTime']]);
        $this->assertSame([], $this->getOrder('2026-11-18'));
        $this->assertSame([['two', 1], ['one', 2], ['three', 3]], $this->getOrder('2026-11-19'));

        // Back to the whole day of another date
        $this->entries->move(self::MEMBER, 1, '2026-11-20', 0, 0);

        $this->assertSame([['one', 1]], $this->getOrder('2026-11-20'));
    }

    public function testDoesNotMoveToAnInvalidSlotOrTheEntryOfAnotherMember(): void
    {
        $this->create('2026-11-18', 'one');

        try {
            $this->entries->move(self::MEMBER, 1, '2026-02-30', 9, 0);

            $this->fail('The slot should be invalid.');
        } catch (ValidationException $exception) {
            $this->assertSame(['entryDate' => 'invalid', 'period' => 'unknownReference'], $exception->getErrors());
        }

        $this->assertSame([['one', 1]], $this->getOrder('2026-11-18'));

        $this->expectException(RecordNotFoundException::class);

        $this->entries->move(self::OTHER_MEMBER, 1, '2026-11-19', 0, 0);
    }

    public function testCopiesAnEntryBelowTheOriginal(): void
    {
        $this->create('2026-11-18', 'one');

        $this->entries->save(self::MEMBER, null, ['type' => 'task', 'entryDate' => '2026-11-18', 'entryTime' => '10:15', 'content' => 'two', 'done' => true, 'important' => true, 'visibleFrom' => '2026-11-10']);

        $this->create('2026-11-18', 'three');

        $copy = $this->entries->copy(self::MEMBER, 2);

        $this->assertSame(
            ['id' => 4, 'type' => 'task', 'entryDate' => '2026-11-18', 'period' => 0, 'entryTime' => '10:15', 'content' => 'two', 'done' => false, 'important' => true, 'visibleFrom' => '2026-11-10', 'location' => '', 'sorting' => 3, 'reminder' => '', 'reminderSent' => false],
            $copy,
        );

        $this->assertSame([['one', 1], ['two', 2], ['two', 3], ['three', 4]], $this->getOrder('2026-11-18'));

        // The original is still done
        $this->assertTrue($this->entries->getByDateRange(self::MEMBER, '2026-11-18', '2026-11-18')[1]['done']);

        $this->expectException(RecordNotFoundException::class);

        $this->entries->copy(self::OTHER_MEMBER, 2);
    }

    public function testDeletesAnEntry(): void
    {
        $this->create('2026-11-18', 'one');

        $this->entries->delete(self::MEMBER, 1);

        $this->assertSame([], $this->entries->getByDateRange(self::MEMBER, '2026-01-01', '2026-12-31'));
    }

    public function testAPeriodWithEntriesCannotBeDeleted(): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-20', 'period' => 1, 'content' => 'one']);

        $this->expectException(RecordInUseException::class);

        $this->masterData->delete(self::MEMBER, 'period', 1);
    }

    public function testEntriesAreNotPartOfTheMasterData(): void
    {
        $this->create('2026-11-18', 'one');

        $this->assertArrayNotHasKey('entry', $this->masterData->getAll(self::MEMBER));
    }

    private function create(string $date, string $content, string $type = 'task'): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => $type, 'entryDate' => $date, 'content' => $content]);
    }

    /**
     * Returns the content and the sorting of the entries of a day in their order.
     *
     * @return list<array{string, int}>
     */
    private function getOrder(string $date): array
    {
        return array_map(
            static fn (array $entry): array => [$entry['content'], $entry['sorting']],
            $this->entries->getByDateRange(self::MEMBER, $date, $date),
        );
    }
}
