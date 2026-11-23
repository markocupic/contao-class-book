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
use Psr\Clock\ClockInterface;

/**
 * A member can share the own class book with other members: the guest stays logged in
 * with the own account and switches to the class book of the owner.
 *
 * A share is entered with the e-mail address of the guest. Whether that address has an
 * account is never told: the share is stored either way and is bound to the member as
 * soon as a member with this address uses the app.
 */
final class ShareService
{
    public const TABLE = 'tl_cb_share';

    public const ACCESS_READ = 'read';

    public const ACCESS_WRITE = 'write';

    // Length of tl_member.email
    public const MAX_LENGTH = 255;

    // More shares than this per member are not accepted
    public const MAX_SHARES = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function normalizeEmail(mixed $email): string
    {
        return \is_string($email) ? mb_strtolower(trim($email)) : '';
    }

    /**
     * @param string $email normalized, see normalizeEmail()
     *
     * @return array<string, string> the error code per field: email (required, tooLong, email, same, tooMany), access (invalid)
     */
    public function validate(int $memberId, string $email, mixed $access): array
    {
        $errors = [];

        if ('' === $email) {
            $errors['email'] = 'required';
        } elseif (mb_strlen($email) > self::MAX_LENGTH) {
            $errors['email'] = 'tooLong';
        } elseif (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'email';
        } elseif ($this->getEmail($memberId) === $email) {
            $errors['email'] = 'same';
        } elseif (null === $this->findId($memberId, $email) && \count($this->getGranted($memberId)) >= self::MAX_SHARES) {
            $errors['email'] = 'tooMany';
        }

        if (!\in_array($access, [self::ACCESS_READ, self::ACCESS_WRITE], true)) {
            $errors['access'] = 'invalid';
        }

        return $errors;
    }

    /**
     * Shares the class book of the member with the address. An existing share for the
     * address gets the new access right.
     *
     * @param string $email  normalized and valid, see validate()
     * @param string $access ACCESS_READ or ACCESS_WRITE
     */
    public function grant(int $memberId, string $email, string $access): void
    {
        $id = $this->findId($memberId, $email);
        $now = $this->clock->now()->getTimestamp();

        if (null !== $id) {
            $this->connection->update(self::TABLE, ['tstamp' => $now, 'access' => $access], ['id' => $id]);

            return;
        }

        // Only an activated account that is allowed to log in counts. Without one the
        // share waits for the address (see bindPending())
        $guests = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_member WHERE LOWER(email) = ? AND login = 1 AND disable = 0 AND id != ?',
            [$email, $memberId],
        );

        $this->connection->insert(self::TABLE, [
            'tstamp' => $now,
            'member' => $memberId,
            'guest' => 1 === \count($guests) ? (int) $guests[0] : 0,
            'guestEmail' => $email,
            'access' => $access,
        ]);
    }

    /**
     * The shares the member has given, with the address as it was entered.
     *
     * @return list<array{id: int, email: string, access: string}>
     */
    public function getGranted(int $memberId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, guestEmail, access FROM '.self::TABLE.' WHERE member = ? ORDER BY guestEmail, id',
            [$memberId],
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'email' => (string) $row['guestEmail'], 'access' => (string) $row['access']],
            $rows,
        );
    }

    /**
     * The class books that are shared with the member.
     *
     * @return list<array{id: int, owner: int, firstname: string, lastname: string, access: string}>
     */
    public function getReceived(int $memberId): array
    {
        $this->bindPending($memberId);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.member, s.access, m.firstname, m.lastname FROM '.self::TABLE.' s INNER JOIN tl_member m ON m.id = s.member WHERE s.guest = ? AND s.member != ? ORDER BY m.lastname, m.firstname, s.id',
            [$memberId, $memberId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'owner' => (int) $row['member'],
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
                'access' => (string) $row['access'],
            ],
            $rows,
        );
    }

    /**
     * The right of the guest in the class book of the owner: ACCESS_READ, ACCESS_WRITE
     * or null without a share.
     */
    public function getAccess(int $ownerId, int $guestId): string|null
    {
        if ($ownerId <= 0 || $guestId <= 0 || $ownerId === $guestId) {
            return null;
        }

        $access = $this->connection->fetchOne(
            'SELECT access FROM '.self::TABLE.' WHERE member = ? AND guest = ? ORDER BY id LIMIT 1',
            [$ownerId, $guestId],
        );

        return \in_array($access, [self::ACCESS_READ, self::ACCESS_WRITE], true) ? $access : null;
    }

    /**
     * Ends a share. The owner can revoke it and the guest can leave it.
     *
     * @return bool false if there is no such share of the member
     */
    public function revoke(int $memberId, int $id): bool
    {
        return 0 < (int) $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE id = ? AND (member = ? OR guest = ?)',
            [$id, $memberId, $memberId],
        );
    }

    /**
     * Binds the shares that wait for the e-mail address of the member to the member.
     * From then on the share follows the member, also with a new e-mail address.
     */
    private function bindPending(int $memberId): void
    {
        $email = $this->getEmail($memberId);

        if ('' === $email) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET guest = ? WHERE guest = 0 AND guestEmail = ? AND member != ?',
            [$memberId, $email, $memberId],
        );
    }

    private function getEmail(int $memberId): string
    {
        return mb_strtolower(trim((string) $this->connection->fetchOne('SELECT email FROM tl_member WHERE id = ?', [$memberId])));
    }

    private function findId(int $memberId, string $email): int|null
    {
        $id = $this->connection->fetchOne('SELECT id FROM '.self::TABLE.' WHERE member = ? AND guestEmail = ? LIMIT 1', [$memberId, $email]);

        return false === $id ? null : (int) $id;
    }
}
