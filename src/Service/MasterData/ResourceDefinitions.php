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
 * Field types: string, integer, date (YYYY-MM-DD), time (HH:MM),
 * color (#rrggbb) and reference (id of a record of another resource).
 */
final class ResourceDefinitions
{
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
                'learningGroup' => ['type' => 'reference', 'required' => true, 'resource' => 'group'],
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
     * Returns the references that point to a resource.
     *
     * @return list<array{resource: string, field: string}>
     */
    public static function getReferencesTo(string $resource): array
    {
        $references = [];

        foreach (self::RESOURCES as $name => $definition) {
            foreach ($definition['fields'] as $field => $config) {
                if ('reference' === $config['type'] && $resource === $config['resource']) {
                    $references[] = ['resource' => $name, 'field' => $field];
                }
            }
        }

        return $references;
    }

    /**
     * The field definitions for the frontend app.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getSchema(): array
    {
        return array_map(static fn (array $definition): array => $definition['fields'], self::RESOURCES);
    }
}
