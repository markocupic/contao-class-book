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
use Markocupic\ContaoClassBook\Service\AccountService;

/**
 * Removes the class book data of members that do not exist any more. The back end
 * deletes the data together with the member (MemberDeleteListener); this job is the
 * safety net for every other way a member can disappear.
 */
#[AsCronJob('daily')]
final class PurgeOrphansCron
{
    public function __construct(private readonly AccountService $accountService)
    {
    }

    public function __invoke(): void
    {
        $this->accountService->deleteOrphans();
    }
}
