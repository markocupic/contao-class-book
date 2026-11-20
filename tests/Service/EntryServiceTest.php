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
            ['id' => 1, 'type' => 'remark', 'entryDate' => '2026-11-20', 'period' => 1, 'entryTime' => '', 'content' => "Kapitel 3 <fertig>\nSeite 12", 'done' => false, 'important' => false, 'visibleFrom' => ''],
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

    public function testReturnsTheOpenEntriesBeforeADate(): void
    {
        $this->create('2026-11-17', 'open');
        $this->create('2026-11-18', 'done');
        $this->create('2026-11-20', 'today');

        $this->entries->setDone(self::MEMBER, 2, true);

        $this->assertSame(['open'], array_column($this->entries->getOpen(self::MEMBER, '2026-11-20'), 'content'));

        // Moving the entry to a later day
        $entry = $this->entries->getOpen(self::MEMBER, '2026-11-20')[0];
        $this->entries->save(self::MEMBER, $entry['id'], [...$entry, 'entryDate' => '2026-11-24']);

        $this->assertSame([], $this->entries->getOpen(self::MEMBER, '2026-11-20'));
        $this->assertSame(['open'], array_column($this->entries->getByDateRange(self::MEMBER, '2026-11-24', '2026-11-24'), 'content'));
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

    private function create(string $date, string $content): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => $date, 'content' => $content]);
    }
}
