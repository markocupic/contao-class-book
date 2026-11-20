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

namespace Markocupic\ContaoClassBook\Service\MasterData;

/**
 * Describes the master data resources: their table, sort order and fields.
 *
 * Field types:
 * - string, integer, date (YYYY-MM-DD), time (HH:MM), color (#rrggbb)
 * - select: one of the given options
 * - reference: id of a record of another resource
 * - references: ids of records of another resource, stored in a junction table
 *
 * "where" limits a reference to records with the given column values.
 * "lockedWhenReferenced" fields cannot be changed while the record is referenced.
 */
final class ResourceDefinitions
{
    public const KIND_CLASS = 'class';

    public const KIND_GROUP = 'group';

    public const RESOURCES = [
        'schoolYear' => [
            'table' => 'tl_cb_school_year',
            'orderBy' => 'startDate DESC, id',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'startDate' => ['type' => 'date', 'required' => true],
                'endDate' => ['type' => 'date', 'required' => true, 'notBefore' => 'startDate'],
            ],
        ],
        'holiday' => [
            'table' => 'tl_cb_holiday',
            'orderBy' => 'startDate, id',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'startDate' => ['type' => 'date', 'required' => true],
                'endDate' => ['type' => 'date', 'required' => true, 'notBefore' => 'startDate'],
            ],
        ],
        'period' => [
            'table' => 'tl_cb_period',
            'orderBy' => 'startTime, id',
            'fields' => [
                'number' => ['type' => 'integer', 'required' => true, 'min' => 1, 'max' => 20],
                'startTime' => ['type' => 'time', 'required' => true],
                'endTime' => ['type' => 'time', 'required' => true, 'after' => 'startTime'],
            ],
        ],
        'subject' => [
            'table' => 'tl_cb_subject',
            'orderBy' => 'title, id',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'shortTitle' => ['type' => 'string', 'required' => true, 'maxLength' => 8],
                'color' => ['type' => 'color', 'required' => true],
            ],
        ],
        'group' => [
            'table' => 'tl_cb_group',
            'orderBy' => 'title, id',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                // A student belongs to exactly one home class and to any number of other learning groups
                'kind' => ['type' => 'select', 'required' => true, 'options' => [self::KIND_CLASS, self::KIND_GROUP], 'lockedWhenReferenced' => true],
                'schoolYear' => ['type' => 'reference', 'required' => true, 'resource' => 'schoolYear'],
            ],
        ],
        'student' => [
            'table' => 'tl_cb_student',
            'orderBy' => 'lastname, firstname, id',
            'fields' => [
                'lastname' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'firstname' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'dateOfBirth' => ['type' => 'date', 'required' => false],
                'homeClass' => ['type' => 'reference', 'required' => true, 'resource' => 'group', 'where' => ['kind' => self::KIND_CLASS]],
                'learningGroups' => [
                    'type' => 'references',
                    'required' => false,
                    'resource' => 'group',
                    'where' => ['kind' => self::KIND_GROUP],
                    'table' => 'tl_cb_student_group',
                    'ownerColumn' => 'student',
                    'targetColumn' => 'learningGroup',
                ],
            ],
        ],
    ];

    public static function has(string $resource): bool
    {
        return isset(self::RESOURCES[$resource]);
    }

    /**
     * @return array{table: string, orderBy: string, fields: array<string, array<string, mixed>>}
     */
    public static function get(string $resource): array
    {
        if (!self::has($resource)) {
            throw new \InvalidArgumentException(\sprintf('Unknown resource "%s".', $resource));
        }

        return self::RESOURCES[$resource];
    }

    /**
     * Returns the fields of all resources that point to a resource.
     *
     * @return list<array{resource: string, field: string, config: array<string, mixed>}>
     */
    public static function getReferencesTo(string $resource): array
    {
        $references = [];

        foreach (self::RESOURCES as $name => $definition) {
            foreach ($definition['fields'] as $field => $config) {
                if (\in_array($config['type'], ['reference', 'references'], true) && $resource === $config['resource']) {
                    $references[] = ['resource' => $name, 'field' => $field, 'config' => $config];
                }
            }
        }

        return $references;
    }

    /**
     * The field definitions for the frontend app, without the storage details.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getSchema(): array
    {
        $internal = ['table' => true, 'ownerColumn' => true, 'targetColumn' => true, 'lockedWhenReferenced' => true];

        return array_map(
            static fn (array $definition): array => array_map(
                static fn (array $config): array => array_diff_key($config, $internal),
                $definition['fields'],
            ),
            self::RESOURCES,
        );
    }
}
