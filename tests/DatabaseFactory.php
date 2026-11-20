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

namespace Markocupic\ContaoClassBook\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;

final class DatabaseFactory
{
    /**
     * Creates an SQLite database in memory with the tables of all resources.
     * The columns correspond to the DCA files.
     */
    public static function create(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (ResourceDefinitions::RESOURCES as $definition) {
            $columns = [];

            foreach ($definition['fields'] as $name => $config) {
                if ('references' === $config['type']) {
                    $connection->executeStatement(\sprintf(
                        'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, member INTEGER NOT NULL DEFAULT 0, %s INTEGER NOT NULL DEFAULT 0, %s INTEGER NOT NULL DEFAULT 0)',
                        $config['table'],
                        $config['ownerColumn'],
                        $config['targetColumn'],
                    ));

                    continue;
                }

                $columns[] = $name.(\in_array($config['type'], ['integer', 'reference', 'boolean'], true) ? ' INTEGER NOT NULL DEFAULT 0' : " TEXT NOT NULL DEFAULT ''");
            }

            $connection->executeStatement(\sprintf(
                'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, member INTEGER NOT NULL DEFAULT 0, %s)',
                $definition['table'],
                implode(', ', $columns),
            ));
        }

        return $connection;
    }
}
