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

namespace Markocupic\ContaoClassBook\Tests\Service\EmailChange;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoClassBook\Service\EmailChange\EmailChangeValidator;
use Markocupic\ContaoClassBook\Service\Registration\EmailDomainRestriction;
use PHPUnit\Framework\TestCase;

class EmailChangeValidatorTest extends TestCase
{
    public function testNormalizesTheAddress(): void
    {
        $validator = $this->getValidator();

        $this->assertSame('fritz@example.org', $validator->normalize('  Fritz@Example.ORG '));
        $this->assertSame('', $validator->normalize(null));
        $this->assertSame('', $validator->normalize(['fritz@example.org']));
    }

    public function testChecksTheAddress(): void
    {
        $validator = $this->getValidator();

        $this->assertNull($validator->validate('new@example.org', 1, ''));
        $this->assertSame('required', $validator->validate('', 1, ''));
        $this->assertSame('tooLong', $validator->validate(str_repeat('a', 250).'@example.org', 1, ''));
        $this->assertSame('email', $validator->validate('fritz', 1, ''));
        $this->assertSame('email', $validator->validate('fritz@', 1, ''));
        $this->assertSame('same', $validator->validate('fritz@example.org', 1, ''));

        // The address of somebody else is not an error: nobody shall find out who has an account
        $this->assertNull($validator->validate('anna@example.org', 1, ''));
    }

    public function testAppliesTheDomainRestriction(): void
    {
        $validator = $this->getValidator();

        $this->assertNull($validator->validate('fritz@schule-ettiswil.ch', 1, 'schule-ettiswil.ch, @schule-grosswangen.ch'));
        $this->assertNull($validator->validate('fritz@schule-grosswangen.ch', 1, 'schule-ettiswil.ch, @schule-grosswangen.ch'));
        $this->assertSame('domain', $validator->validate('fritz@gmail.com', 1, 'schule-ettiswil.ch'));
        $this->assertSame('domain', $validator->validate('fritz@mail.schule-ettiswil.ch', 1, 'schule-ettiswil.ch'));
    }

    public function testKnowsAddressesAndNamesOfOtherMembers(): void
    {
        $validator = $this->getValidator();

        $this->assertTrue($validator->isTaken('anna@example.org', 1));

        // A user name that looks like an address counts too, members log in with either
        $this->assertTrue($validator->isTaken('boss@example.org', 1));
        $this->assertFalse($validator->isTaken('new@example.org', 1));
        $this->assertFalse($validator->isTaken('anna@example.org', 2));
    }

    private function getValidator(): EmailChangeValidator
    {
        return new EmailChangeValidator($this->getConnection(), new EmailDomainRestriction());
    }

    private function getConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, email TEXT NOT NULL)');

        $connection->insert('tl_member', ['id' => 1, 'username' => 'fritz', 'email' => 'Fritz@example.org']);
        $connection->insert('tl_member', ['id' => 2, 'username' => 'anna', 'email' => 'Anna@Example.org']);
        $connection->insert('tl_member', ['id' => 3, 'username' => 'Boss@example.org', 'email' => 'chef@example.org']);

        return $connection;
    }
}
