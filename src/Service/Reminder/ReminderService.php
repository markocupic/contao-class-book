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

namespace Markocupic\ContaoClassBook\Service\Reminder;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Service\EntryService;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;
use Psr\Clock\ClockInterface;

/**
 * Sends the reminders of the entries by e-mail, for all members.
 *
 * An entry begins at its time, else at the start of its period, else (whole day
 * without a time) at WHOLE_DAY_TIME. The reminder is due the chosen period before
 * that. It is sent once, tasks that are done and entries that have begun get none.
 */
final class ReminderService
{
    public const WHOLE_DAY_TIME = '07:00';

    // The longest reminder is three months
    private const DAYS_AHEAD = 93;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly ReminderMailerInterface $mailer,
    ) {
    }

    /**
     * Sends the reminders that are due.
     *
     * @return int The number of reminders that have been sent
     */
    public function sendDue(): int
    {
        $now = $this->clock->now();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.*, p.number AS periodNumber, p.startTime AS periodStart, p.endTime AS periodEnd
            FROM tl_cb_entry e LEFT JOIN tl_cb_period p ON p.id = e.period AND p.member = e.member
            WHERE e.reminder != '' AND e.reminderSent = 0 AND e.entryDate >= ? AND e.entryDate <= ?
            ORDER BY e.entryDate, e.id",
            [$now->format('Y-m-d'), $now->modify('+'.self::DAYS_AHEAD.' days')->format('Y-m-d')],
        );

        $count = 0;

        foreach ($rows as $row) {
            $start = $this->getStart($row);
            $period = ResourceDefinitions::REMINDERS[$row['reminder']] ?? null;

            if (null === $start || null === $period || $start <= $now || $start->modify('-'.$period) > $now) {
                continue;
            }

            if (EntryService::TYPE_TASK === $row['type'] && $row['done']) {
                continue;
            }

            $this->mailer->send(
                [
                    'id' => (int) $row['id'],
                    'member' => (int) $row['member'],
                    'type' => (string) $row['type'],
                    'content' => StringUtil::decodeEntities((string) $row['content']),
                    'entryDate' => (string) $row['entryDate'],
                    'entryTime' => (string) $row['entryTime'],
                    'period' => (int) $row['period'],
                    'periodNumber' => (int) $row['periodNumber'],
                    'periodStart' => (string) $row['periodStart'],
                    'periodEnd' => (string) $row['periodEnd'],
                ],
                $start,
            );

            $this->connection->update('tl_cb_entry', ['reminderSent' => 1], ['id' => $row['id']]);

            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getStart(array $row): \DateTimeImmutable|null
    {
        $time = (string) $row['entryTime'];

        if ('' === $time) {
            $time = (int) $row['period'] > 0 ? (string) $row['periodStart'] : self::WHOLE_DAY_TIME;
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $row['entryDate'].' '.$time, $this->clock->now()->getTimezone());

        return false === $start ? null : $start;
    }
}
