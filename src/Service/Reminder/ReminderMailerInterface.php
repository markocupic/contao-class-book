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

interface ReminderMailerInterface
{
    /**
     * Sends the reminder for an entry to its member.
     *
     * @param array<string, int|string> $entry The entry with its member and the number, start and end of its period
     * @param \DateTimeImmutable       $start When the entry begins
     */
    public function send(array $entry, \DateTimeImmutable $start): void;
}
