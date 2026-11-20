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
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;

/*
 * The records are managed in the frontend app. The back end is for emergency access only.
 * Dates are stored as YYYY-MM-DD, times as HH:MM.
 */
$GLOBALS['TL_DCA']['tl_cb_group'] = [
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
            'fields' => ['title'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['title', 'kind'],
            'format' => '%s <span class="label-info">[%s]</span>',
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},title,kind,schoolYear;{seating_legend},classroom,seating;{member_legend},member',
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
        'kind' => [
            'filter' => true,
            'inputType' => 'select',
            'options' => [ResourceDefinitions::KIND_CLASS, ResourceDefinitions::KIND_GROUP],
            'reference' => &$GLOBALS['TL_LANG']['tl_cb_group']['kindOptions'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(8) NOT NULL default ''",
        ],
        'schoolYear' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_school_year.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'classroom' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_cb_classroom.title',
            'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        // JSON, written by the frontend app
        'seating' => [
            'inputType' => 'textarea',
            'eval' => ['decodeEntities' => true, 'preserveTags' => true, 'tl_class' => 'clr'],
            'sql' => 'text NULL',
        ],
    ],
];
