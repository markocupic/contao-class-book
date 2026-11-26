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
 * - string, text (multi-line), integer, boolean, date (YYYY-MM-DD), time (HH:MM)
 * - color (#rrggbb), with "options" only the given colours
 * - select: one of the given options
 * - json: a JSON document as a string, stored as it is (the frontend app owns the structure)
 * - reference: id of a record of another resource
 * - references: ids of records of another resource, stored in a junction table
 *
 * "where" limits a reference to records with the given column values.
 * "lockedWhenReferenced" fields cannot be changed while the record is referenced.
 * "unique" lists fields whose combination may exist only once per member.
 * "hidden" fields are not shown in the generic lists and forms of the frontend app.
 * "masterData" => false excludes a resource from the master data lists and API.
 */
final class ResourceDefinitions
{
    /**
     * The colours a subject can have. They are tuned to the design: a lesson
     * mixes the colour into its background and its text, in the light and in
     * the dark theme. The names are in the language files (options.color).
     */
    public const SUBJECT_COLORS = ['#2563eb', '#0891d1', '#0d9488', '#16a34a', '#65a30d', '#eab308', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#92400e', '#475569'];

    public const KIND_CLASS = 'class';

    public const KIND_GROUP = 'group';

    public const GENDER_FEMALE = 'f';

    public const GENDER_MALE = 'm';

    /**
     * How long before an entry its reminder is sent by e-mail: the option and the
     * period for DateTimeImmutable::modify(). The names are in the language files (options.reminder).
     */
    public const REMINDERS = [
        '15m' => '15 minutes',
        '30m' => '30 minutes',
        '45m' => '45 minutes',
        '1h' => '1 hour',
        '2h' => '2 hours',
        '3h' => '3 hours',
        '4h' => '4 hours',
        '5h' => '5 hours',
        '6h' => '6 hours',
        '7h' => '7 hours',
        '8h' => '8 hours',
        '1d' => '1 day',
        '2d' => '2 days',
        '3d' => '3 days',
        '4d' => '4 days',
        '5d' => '5 days',
        '6d' => '6 days',
        '1w' => '1 week',
        '2w' => '2 weeks',
        '3w' => '3 weeks',
        '1mo' => '1 month',
        '2mo' => '2 months',
        '3mo' => '3 months',
    ];

    public const RESOURCES = [
        'schoolYear' => [
            'table' => 'tl_cb_school_year',
            'orderBy' => 'startDate DESC, id',
            // A date belongs to one school year at most: the timetable of a day and
            // everything else that asks for "the school year of a date" relies on it
            'noOverlap' => ['startDate', 'endDate'],
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
                'color' => ['type' => 'color', 'required' => true, 'options' => self::SUBJECT_COLORS],
            ],
        ],
        // A room with its desks. The layout is drawn in the frontend app: {desks: [{id, type, x, y, r}]}
        'classroom' => [
            'table' => 'tl_cb_classroom',
            'orderBy' => 'title, id',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'layout' => ['type' => 'json', 'required' => false, 'maxLength' => 20000, 'hidden' => true],
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
                'classroom' => ['type' => 'reference', 'required' => false, 'resource' => 'classroom'],
                // The seating plan of the group in its classroom: {seats: {seat: student}, blocked: [seat]}
                'seating' => ['type' => 'json', 'required' => false, 'maxLength' => 20000, 'hidden' => true],
            ],
        ],
        'student' => [
            'table' => 'tl_cb_student',
            'orderBy' => 'lastname, firstname, id',
            'fields' => [
                'lastname' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'firstname' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'dateOfBirth' => ['type' => 'date', 'required' => false],
                // Only used to mix girls and boys in the seating plan
                'gender' => ['type' => 'select', 'required' => false, 'options' => [self::GENDER_FEMALE, self::GENDER_MALE]],
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
                // The title of a classroom (the app offers the classrooms as options) or any other text
                'room' => ['type' => 'string', 'required' => false, 'maxLength' => 64],
            ],
        ],
        // The entries of the class book. They are not part of the master data lists (see EntryService)
        'entry' => [
            'table' => 'tl_cb_entry',
            'orderBy' => 'entryDate, period, sorting, entryTime, id',
            'masterData' => false,
            'fields' => [
                'type' => ['type' => 'select', 'required' => true, 'options' => ['remark', 'note', 'task', 'appointment', 'exam', 'parentMeeting']],
                'entryDate' => ['type' => 'date', 'required' => true],
                // Without a period the entry belongs to the day
                'period' => ['type' => 'reference', 'required' => false, 'resource' => 'period'],
                'entryTime' => ['type' => 'time', 'required' => false],
                'content' => ['type' => 'text', 'required' => true, 'maxLength' => 5000],
                // Only tasks can be done
                'done' => ['type' => 'boolean', 'required' => false],
                'important' => ['type' => 'boolean', 'required' => false],
                'visibleFrom' => ['type' => 'date', 'required' => false],
                // Where an appointment takes place, appointments only (see EntryService)
                'location' => ['type' => 'string', 'required' => false, 'maxLength' => 128],
                // The position within the period or the whole day, set by EntryService
                'sorting' => ['type' => 'integer', 'required' => false, 'min' => 0, 'max' => 1000000],
                // The member gets an e-mail that long before the entry (see ReminderService)
                'reminder' => ['type' => 'select', 'required' => false, 'options' => ['15m', '30m', '45m', '1h', '2h', '3h', '4h', '5h', '6h', '7h', '8h', '1d', '2d', '3d', '4d', '5d', '6d', '1w', '2w', '3w', '1mo', '2mo', '3mo']],
                // Whether the reminder has been sent, set by EntryService and ReminderService
                'reminderSent' => ['type' => 'boolean', 'required' => false],
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
