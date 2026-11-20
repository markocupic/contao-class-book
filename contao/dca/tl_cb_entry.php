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
 * The entries of the class book: notes, appointments and lesson remarks.
 * They are managed in the frontend app. The back end is for emergency access only.
 * Dates are stored as YYYY-MM-DD, times as HH:MM.
 */
$GLOBALS['TL_DCA']['tl_cb_entry'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'member,entryDate' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTABLE,
            'fields' => ['entryDate DESC'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['entryDate', 'content'],
            'format' => '%s: %s',
            'maxCharacters' => 96,
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},type,entryDate,period,entryTime,content;{state_legend},done,important,visibleFrom;{member_legend},member',
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
        'type' => [
            'filter' => true,
            'inputType' => 'select',
            'options' => ['note', 'appointment', 'remark'],
            'reference' => &$GLOBALS['TL_LANG']['tl_cb_entry']['typeOptions'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        'entryDate' => [
            'sorting' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 10, 'tl_class' => 'w25'],
            'sql' => "varchar(10) NOT NULL default ''",
        ],
        'period' => [
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_period.number',
            'eval' => ['includeBlankOption' => true, 'tl_class' => 'w25 clr'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'entryTime' => [
            'inputType' => 'text',
            'eval' => ['maxlength' => 5, 'tl_class' => 'w25'],
            'sql' => "varchar(5) NOT NULL default ''",
        ],
        'content' => [
            'search' => true,
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'maxlength' => 5000, 'tl_class' => 'clr'],
            'sql' => 'text NULL',
        ],
        'done' => [
            'filter' => true,
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w25'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'important' => [
            'filter' => true,
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w25'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'visibleFrom' => [
            'inputType' => 'text',
            'eval' => ['maxlength' => 10, 'tl_class' => 'w25'],
            'sql' => "varchar(10) NOT NULL default ''",
        ],
    ],
];
