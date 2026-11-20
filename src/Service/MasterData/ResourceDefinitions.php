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
 * - string, text (multi-line), integer, boolean, date (YYYY-MM-DD), time (HH:MM), color (#rrggbb)
 * - select: one of the given options
 * - reference: id of a record of another resource
 * - references: ids of records of another resource, stored in a junction table
 *
 * "where" limits a reference to records with the given column values.
 * "lockedWhenReferenced" fields cannot be changed while the record is referenced.
 * "unique" lists fields whose combination may exist only once per member.
 * "masterData" => false excludes a resource from the master data lists and API.
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
        // The timetable: one lesson per school year, weekday and period
        'lesson' => [
            'table' => 'tl_cb_lesson',
            'orderBy' => 'schoolYear, weekday, id',
            'unique' => ['schoolYear', 'weekday', 'period'],
            'fields' => [
                'schoolYear' => ['type' => 'reference', 'required' => true, 'resource' => 'schoolYear'],
                'weekday' => ['type' => 'integer', 'required' => true, 'min' => 1, 'max' => 7],
                'period' => ['type' => 'reference', 'required' => true, 'resource' => 'period'],
                'subject' => ['type' => 'reference', 'required' => true, 'resource' => 'subject'],
                'learningGroup' => ['type' => 'reference', 'required' => true, 'resource' => 'group'],
                'room' => ['type' => 'string', 'required' => false, 'maxLength' => 32],
            ],
        ],
        // The entries of the class book. They are not part of the master data lists (see EntryService)
        'entry' => [
            'table' => 'tl_cb_entry',
            'orderBy' => 'entryDate, entryTime, id',
            'masterData' => false,
            'fields' => [
                'type' => ['type' => 'select', 'required' => true, 'options' => ['note', 'task', 'appointment', 'remark']],
                'entryDate' => ['type' => 'date', 'required' => true],
                // Without a period the entry belongs to the day
                'period' => ['type' => 'reference', 'required' => false, 'resource' => 'period'],
                'entryTime' => ['type' => 'time', 'required' => false],
                'content' => ['type' => 'text', 'required' => true, 'maxLength' => 5000],
                // Only tasks can be done
                'done' => ['type' => 'boolean', 'required' => false],
                'important' => ['type' => 'boolean', 'required' => false],
                'visibleFrom' => ['type' => 'date', 'required' => false],
            ],
        ],
    ];

    public static function has(string $resource): bool
    {
        return isset(self::RESOURCES[$resource]);
    }

    /**
     * Master data is loaded completely and edited in the settings.
     */
    public static function isMasterData(string $resource): bool
    {
        return self::has($resource) && (self::RESOURCES[$resource]['masterData'] ?? true);
    }

    /**
     * @return list<string>
     */
    public static function getMasterDataResources(): array
    {
        return array_values(array_filter(array_keys(self::RESOURCES), self::isMasterData(...)));
    }

    /**
     * @return array{table: string, orderBy: string, unique?: list<string>, fields: array<string, array<string, mixed>>}
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
     * The field definitions of the master data for the frontend app, without the storage details.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getSchema(): array
    {
        $internal = ['table' => true, 'ownerColumn' => true, 'targetColumn' => true, 'lockedWhenReferenced' => true];

        $schema = [];

        foreach (self::getMasterDataResources() as $resource) {
            $schema[$resource] = array_map(
                static fn (array $config): array => array_diff_key($config, $internal),
                self::RESOURCES[$resource]['fields'],
            );
        }

        return $schema;
    }
}
