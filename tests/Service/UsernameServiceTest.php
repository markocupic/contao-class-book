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

namespace Markocupic\ContaoClassBook\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoClassBook\Service\UsernameService;
use PHPUnit\Framework\TestCase;

class UsernameServiceTest extends TestCase
{
    public function testGeneratesAValidUserName(): void
    {
        $service = new UsernameService($this->getConnection());
        $username = $service->generate();

        $this->assertSame(1, preg_match('/^cbuser_[a-z]{3}_\d{3}$/', $username));
        $this->assertNull($service->validate($username, 0));
    }

    public function testAppliesTheRulesOfContao(): void
    {
        $service = new UsernameService($this->getConnection());

        $this->assertNull($service->validate('anna.muster', 3));
        $this->assertNull($service->validate('Anna_Muster-2@example.org', 3));
        $this->assertSame('required', $service->validate('', 3));
        $this->assertSame('tooLong', $service->validate(str_repeat('a', 65), 3));
        $this->assertNull($service->validate(str_repeat('a', 64), 3));
        $this->assertSame('noSpace', $service->validate('anna muster', 3));
        $this->assertSame('noSpace', $service->validate("anna\tmuster", 3));

        foreach (['#', '&', '(', ')', '/', '<', '=', '>'] as $character) {
            $this->assertSame('invalid', $service->validate('anna'.$character, 3));
        }
    }

    public function testRefusesNamesAndAddressesOfOtherMembers(): void
    {
        $service = new UsernameService($this->getConnection());

        $this->assertSame('notUnique', $service->validate('fritz', 2));
        $this->assertSame('notUnique', $service->validate('FRITZ', 2));
        $this->assertSame('notUnique', $service->validate('Fritz@Example.org', 2));

        // The own name and the own e-mail address are fine
        $this->assertNull($service->validate('Fritz', 1));
        $this->assertNull($service->validate('fritz@example.org', 1));
    }

    private function getConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, email TEXT NOT NULL)');

        $connection->insert('tl_member', ['id' => 1, 'username' => 'fritz', 'email' => 'fritz@example.org']);
        $connection->insert('tl_member', ['id' => 2, 'username' => 'anna', 'email' => 'anna@example.org']);

        return $connection;
    }
}
