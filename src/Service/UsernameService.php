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

namespace Markocupic\ContaoClassBook\Service;

use Doctrine\DBAL\Connection;

/**
 * The user name of a member: a generated one for a new registration and the
 * rules for a name the member chooses.
 *
 * The rules are those of the field "username" of tl_member in the Contao core:
 * mandatory, unique, at most 64 characters, no spaces and the regular expression
 * "extnd" (none of the characters #&()/<=>).
 */
final class UsernameService
{
    public const MAX_LENGTH = 64;

    public const PREFIX = 'cbuser_';

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A user name that is not in use yet, e.g. "cbuser_xyz_123".
     */
    public function generate(): string
    {
        do {
            $letters = '';

            for ($i = 0; $i < 3; ++$i) {
                $letters .= self::LETTERS[random_int(0, \strlen(self::LETTERS) - 1)];
            }

            $username = \sprintf('%s%s_%03d', self::PREFIX, $letters, random_int(0, 999));
        } while ($this->isTaken($username, 0));

        return $username;
    }

    /**
     * @return string|null the error code or null if the member may use the name
     */
    public function validate(string $username, int $memberId): string|null
    {
        if ('' === $username) {
            return 'required';
        }

        if (mb_strlen($username) > self::MAX_LENGTH) {
            return 'tooLong';
        }

        if (preg_match('/\s/u', $username)) {
            return 'noSpace';
        }

        // The same characters Contao refuses (Validator::isExtendedAlphanumeric)
        if (preg_match('/[#&()\/<=>]/', $username)) {
            return 'invalid';
        }

        return $this->isTaken($username, $memberId) ? 'notUnique' : null;
    }

    /**
     * A name is also taken if it is the e-mail address of another member,
     * because members can log in with their e-mail address.
     */
    private function isTaken(string $username, int $memberId): bool
    {
        $name = mb_strtolower($username);

        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_member WHERE id != ? AND (LOWER(username) = ? OR LOWER(email) = ?) LIMIT 1',
            [$memberId, $name, $name],
        );
    }
}
