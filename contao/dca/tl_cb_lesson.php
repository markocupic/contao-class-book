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

use Contao\DataContainer;
use Contao\DC_Table;

/*
 * The lessons of the timetable. They are managed in the frontend app.
 * The back end is for emergency access only.
 */
$GLOBALS['TL_DCA']['tl_cb_lesson'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'member' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTABLE,
            'fields' => ['weekday'],
            'panelLayout' => 'filter;sort,limit',
        ],
        'label' => [
            'fields' => ['weekday', 'period:tl_cb_period.number', 'subject:tl_cb_subject.title', 'learningGroup:tl_cb_group.title'],
            'format' => '%s / %s: %s <span class="label-info">[%s]</span>',
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},schoolYear,weekday,period,subject,learningGroup,room;{member_legend},member',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'member' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_member.username',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'schoolYear' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_school_year.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'weekday' => [
            'filter' => true,
            'inputType' => 'select',
            'options' => [1, 2, 3, 4, 5, 6, 7],
            'reference' => &$GLOBALS['TL_LANG']['tl_cb_lesson']['weekdayOptions'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => 'smallint(5) unsigned NOT NULL default 0',
        ],
        'period' => [
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_period.number',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'subject' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_subject.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'learningGroup' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_group.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'room' => [
            'inputType' => 'text',
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => "varchar(64) NOT NULL default ''"
        ],
    ],
];
