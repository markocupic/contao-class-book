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

namespace Markocupic\ContaoClassBook\Tests\Service\Reminder;

use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Service\EntryService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;
use Markocupic\ContaoClassBook\Service\Reminder\ReminderMailerInterface;
use Markocupic\ContaoClassBook\Service\Reminder\ReminderService;
use Markocupic\ContaoClassBook\Tests\DatabaseFactory;
use Markocupic\ContaoClassBook\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

class ReminderServiceTest extends TestCase
{
    private const MEMBER = 1;

    private const OTHER_MEMBER = 2;

    private Connection $connection;

    private FixedClock $clock;

    private EntryService $entries;

    private ReminderService $reminders;

    /**
     * @var list<string> The sent reminders as "member|content|start"
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->connection = DatabaseFactory::create();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-20 08:00:00'));

        $masterData = new MasterDataService($this->connection, new MasterDataValidator(), $this->clock);

        $this->entries = new EntryService($masterData);

        $mailer = new class($this->sent) implements ReminderMailerInterface {
            /**
             * @param list<string> $sent
             */
            public function __construct(private array &$sent)
            {
            }

            public function send(array $entry, \DateTimeImmutable $start): void
            {
                $this->sent[] = $entry['member'].'|'.$entry['content'].'|'.$start->format('Y-m-d H:i');
            }
        };

        $this->reminders = new ReminderService($this->connection, $this->clock, $mailer);

        $masterData->save(self::MEMBER, 'period', null, ['number' => 3, 'startTime' => '09:00', 'endTime' => '09:45']);
    }

    public function testTheOptionsMatchThePeriods(): void
    {
        $this->assertSame(ResourceDefinitions::RESOURCES['entry']['fields']['reminder']['options'], array_keys(ResourceDefinitions::REMINDERS));
    }

    public function testSendsAReminderOnceWhenItIsDue(): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'appointment', 'entryDate' => '2026-11-20', 'entryTime' => '16:30', 'content' => 'Elterngespräch & Apéro', 'reminder' => '2h']);

        // Due at 14:30
        $this->assertSame(0, $this->reminders->sendDue());

        $this->clock->modify('14:29');

        $this->assertSame(0, $this->reminders->sendDue());

        $this->clock->modify('14:30');

        $this->assertSame(1, $this->reminders->sendDue());
        $this->assertSame(['1|Elterngespräch & Apéro|2026-11-20 16:30'], $this->sent);
        $this->assertSame(0, $this->reminders->sendDue());
        $this->assertTrue($this->entries->getByDateRange(self::MEMBER, '2026-11-20', '2026-11-20')[0]['reminderSent']);
    }

    public function testAnEntryBeginsAtItsTimeItsPeriodOrInTheMorning(): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-23', 'period' => 1, 'content' => 'Lektion', 'reminder' => '1d']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-24', 'content' => 'Ganzer Tag', 'reminder' => '1w']);
        $this->entries->save(self::OTHER_MEMBER, null, ['type' => 'note', 'entryDate' => '2027-02-20', 'entryTime' => '08:00', 'content' => 'In drei Monaten', 'reminder' => '3mo']);

        $this->assertSame(2, $this->reminders->sendDue());
        $this->assertSame(['1|Ganzer Tag|2026-11-24 07:00', '2|In drei Monaten|2027-02-20 08:00'], $this->sent);

        // One day before the period starts at 09:00
        $this->clock->modify('2026-11-22 09:00');

        $this->assertSame(1, $this->reminders->sendDue());
        $this->assertSame('1|Lektion|2026-11-23 09:00', $this->sent[2]);
    }

    public function testNoReminderForDoneTasksAndEntriesThatHaveBegun(): void
    {
        $this->entries->save(self::MEMBER, null, ['type' => 'task', 'entryDate' => '2026-11-20', 'entryTime' => '10:00', 'content' => 'Erledigt', 'done' => true, 'reminder' => '8h']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-20', 'entryTime' => '07:45', 'content' => 'Vorbei', 'reminder' => '15m']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-19', 'entryTime' => '10:00', 'content' => 'Gestern', 'reminder' => '15m']);
        $this->entries->save(self::MEMBER, null, ['type' => 'note', 'entryDate' => '2026-11-20', 'entryTime' => '10:00', 'content' => 'Ohne Erinnerung']);

        $this->assertSame(0, $this->reminders->sendDue());
        $this->assertSame([], $this->sent);
    }

    public function testAReminderIsSentAgainIfTheEntryIsMovedOrGetsAnotherReminder(): void
    {
        $entry = $this->entries->save(self::MEMBER, null, ['type' => 'appointment', 'entryDate' => '2026-11-20', 'entryTime' => '09:00', 'content' => 'Sitzung', 'reminder' => '1h']);

        $this->assertSame(1, $this->reminders->sendDue());

        // Another text: no new reminder
        $entry = $this->entries->save(self::MEMBER, 1, [...$entry, 'content' => 'Sitzung im Lehrerzimmer']);

        $this->assertSame(0, $this->reminders->sendDue());

        // Another time
        $entry = $this->entries->save(self::MEMBER, 1, [...$entry, 'entryTime' => '08:30']);

        $this->assertSame(1, $this->reminders->sendDue());

        // Dragged to another day
        $this->entries->move(self::MEMBER, 1, '2026-11-21', 0, 0);
        $this->assertSame(0, $this->reminders->sendDue());

        $this->clock->modify('2026-11-21 07:30');

        $this->assertSame(1, $this->reminders->sendDue());

        // The copy of an entry has its own reminder
        $this->entries->copy(self::MEMBER, 1);

        $this->assertSame(1, $this->reminders->sendDue());
        $this->assertCount(4, $this->sent);
    }
}
