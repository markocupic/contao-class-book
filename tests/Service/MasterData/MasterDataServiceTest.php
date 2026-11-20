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
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoClassBook\Exception\RecordInUseException;
use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;
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
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // The tables with the columns of the DCA files
        foreach (ResourceDefinitions::RESOURCES as $definition) {
            $columns = array_map(
                static fn (string $name, array $config): string => $name.(\in_array($config['type'], ['integer', 'reference'], true) ? ' INTEGER NOT NULL DEFAULT 0' : " TEXT NOT NULL DEFAULT ''"),
                array_keys($definition['fields']),
                $definition['fields'],
            );

            $this->connection->executeStatement(\sprintf(
                'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, member INTEGER NOT NULL DEFAULT 0, %s)',
                $definition['table'],
                implode(', ', $columns),
            ));
        }

        $this->service = new MasterDataService(
            $this->connection,
            new MasterDataValidator(),
            new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00')),
        );
    }

    public function testCreatesAndUpdatesARecord(): void
    {
        $record = $this->service->save(self::MEMBER, 'subject', null, ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#1f6feb']);

        $this->assertSame(['id' => 1, 'title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#1f6feb'], $record);

        $record = $this->service->save(self::MEMBER, 'subject', 1, ['title' => 'Mathe', 'shortTitle' => 'M', 'color' => '#000000']);

        $this->assertSame(['id' => 1, 'title' => 'Mathe', 'shortTitle' => 'M', 'color' => '#000000'], $record);
        $this->assertSame([$record], $this->service->getList(self::MEMBER, 'subject'));

        $row = $this->connection->fetchAssociative('SELECT member, tstamp FROM tl_cb_subject WHERE id = 1');

        $this->assertSame(self::MEMBER, (int) $row['member']);
        $this->assertSame((new \DateTimeImmutable('2026-11-20 08:00:00'))->getTimestamp(), (int) $row['tstamp']);
    }

    public function testStoresStringsEncodedAndReturnsThemDecoded(): void
    {
        $this->service->save(self::MEMBER, 'subject', null, ['title' => 'Medien & <b>Informatik</b>', 'shortTitle' => 'M&I', 'color' => '#000000']);

        $this->assertSame('Medien &amp; &lt;b&gt;Informatik&lt;/b&gt;', $this->connection->fetchOne('SELECT title FROM tl_cb_subject'));
        $this->assertSame('Medien & <b>Informatik</b>', $this->service->getList(self::MEMBER, 'subject')[0]['title']);
    }

    public function testReturnsTheRecordsOfTheMemberInTheDefinedOrder(): void
    {
        $this->service->save(self::MEMBER, 'period', null, ['number' => 2, 'startTime' => '08:20', 'endTime' => '09:05']);
        $this->service->save(self::MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:30', 'endTime' => '08:15']);
        $this->service->save(self::OTHER_MEMBER, 'period', null, ['number' => 1, 'startTime' => '07:00', 'endTime' => '07:45']);

        $data = $this->service->getAll(self::MEMBER);

        $this->assertSame(['schoolYear', 'holiday', 'period', 'subject', 'group'], array_keys($data));
        $this->assertSame([1, 2], array_column($data['period'], 'number'));
        $this->assertSame(['07:00'], array_column($this->service->getList(self::OTHER_MEMBER, 'period'), 'startTime'));
    }

    public function testDoesNotSaveInvalidData(): void
    {
        try {
            $this->service->save(self::MEMBER, 'subject', null, ['title' => '', 'shortTitle' => 'MA', 'color' => '#000000']);

            $this->fail('The data should be invalid.');
        } catch (ValidationException $exception) {
            $this->assertSame(['title' => 'required'], $exception->getErrors());
        }

        $this->assertSame([], $this->service->getList(self::MEMBER, 'subject'));
    }

    public function testCannotUpdateTheRecordOfAnotherMember(): void
    {
        $this->service->save(self::OTHER_MEMBER, 'subject', null, ['title' => 'Deutsch', 'shortTitle' => 'DE', 'color' => '#000000']);

        $this->expectException(RecordNotFoundException::class);

        $this->service->save(self::MEMBER, 'subject', 1, ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#000000']);
    }

    public function testCannotDeleteTheRecordOfAnotherMember(): void
    {
        $this->service->save(self::OTHER_MEMBER, 'subject', null, ['title' => 'Deutsch', 'shortTitle' => 'DE', 'color' => '#000000']);

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
            $this->service->save(self::MEMBER, 'group', null, ['title' => '2a', 'schoolYear' => 1]);

            $this->fail('The reference should be unknown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['schoolYear' => 'unknownReference'], $exception->getErrors());
        }
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
        $this->service->save(self::MEMBER, 'group', null, ['title' => '2a', 'schoolYear' => 1]);

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
}
