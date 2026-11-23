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

namespace Markocupic\ContaoClassBook\Security;

/**
 * Payload of a validated access token.
 */
final class AccessToken
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $elementId,
        public readonly int $expires,
        // The member whose class book the requests work on: the member itself or,
        // with a share, somebody else
        public readonly int $ownerId,
    ) {
    }
}
