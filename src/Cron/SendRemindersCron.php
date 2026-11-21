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

namespace Markocupic\ContaoClassBook\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Markocupic\ContaoClassBook\Service\Reminder\ReminderService;

/**
 * Sends the reminders of the entries that are due. Runs with the Contao cron,
 * so the e-mails are only as punctual as the cron job of the installation.
 */
#[AsCronJob('minutely')]
final class SendRemindersCron
{
    public function __construct(private readonly ReminderService $reminders)
    {
    }

    public function __invoke(): void
    {
        $this->reminders->sendDue();
    }
}
