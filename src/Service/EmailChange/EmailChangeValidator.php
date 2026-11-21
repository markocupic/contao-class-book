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

namespace Markocupic\ContaoClassBook\Service\EmailChange;

use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Service\Registration\EmailDomainRestriction;

/**
 * Checks the new e-mail address of a member.
 *
 * Whether the address belongs to somebody else is not an error on purpose (see isTaken()):
 * an error would tell who has an account.
 */
final class EmailChangeValidator
{
    // Length of tl_member.email
    public const MAX_LENGTH = 255;

    public function __construct(
        private readonly Connection $connection,
        private readonly EmailDomainRestriction $domainRestriction,
    ) {
    }

    public function normalize(mixed $email): string
    {
        return \is_string($email) ? mb_strtolower(trim($email)) : '';
    }

    /**
     * @param string $email          normalized, see normalize()
     * @param string $allowedDomains the domain restriction of the content element
     *
     * @return string|null the error code: required, tooLong, email, domain or same
     */
    public function validate(string $email, int $memberId, string $allowedDomains): string|null
    {
        if ('' === $email) {
            return 'required';
        }

        if (mb_strlen($email) > self::MAX_LENGTH) {
            return 'tooLong';
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        // The restriction of the registration applies here too, otherwise it could be bypassed
        if (!$this->domainRestriction->isAllowed($email, $allowedDomains)) {
            return 'domain';
        }

        $current = $this->connection->fetchOne('SELECT email FROM tl_member WHERE id = ?', [$memberId]);

        return mb_strtolower((string) $current) === $email ? 'same' : null;
    }

    /**
     * An address is taken if it is the e-mail address or the user name of another member:
     * members log in with either of them.
     */
    public function isTaken(string $email, int $memberId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_member WHERE id != ? AND (LOWER(email) = ? OR LOWER(username) = ?) LIMIT 1',
            [$memberId, $email, $email],
        );
    }
}
