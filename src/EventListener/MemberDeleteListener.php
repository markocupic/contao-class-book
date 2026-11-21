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

namespace Markocupic\ContaoClassBook\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Markocupic\ContaoClassBook\Service\AccountService;

/**
 * A member that is deleted in the back end loses the class book data at once.
 *
 * The data is not part of the undo record of Contao: restoring the member brings
 * back the account, not its class book.
 */
#[AsCallback(table: 'tl_member', target: 'config.ondelete')]
final class MemberDeleteListener
{
    public function __construct(private readonly AccountService $accountService)
    {
    }

    public function __invoke(DataContainer $dc): void
    {
        if ($dc->id > 0) {
            $this->accountService->deleteData((int) $dc->id);
        }
    }
}
