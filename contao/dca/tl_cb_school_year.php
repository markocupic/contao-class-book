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
 * The records are managed in the frontend app. The back end is for emergency access only.
 * Dates are stored as YYYY-MM-DD, times as HH:MM.
 */
$GLOBALS['TL_DCA']['tl_cb_school_year'] = [
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
            'fields' => ['startDate'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['title', 'startDate', 'endDate'],
            'format' => '%s <span class="label-info">[%s – %s]</span>',
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},title,startDate,endDate;{member_legend},member',
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
        'title' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'startDate' => [
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 10, 'tl_class' => 'w25'],
            'sql' => "varchar(10) NOT NULL default ''",
        ],
        'endDate' => [
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 10, 'tl_class' => 'w25'],
            'sql' => "varchar(10) NOT NULL default ''",
        ],
    ],
];
