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
 * A member shares the own class book with another member (see ShareService).
 * The shares are managed in the frontend app. The back end is for emergency access only.
 */
$GLOBALS['TL_DCA']['tl_cb_share'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'member' => 'index',
                'guest' => 'index',
                'guestEmail' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTABLE,
            'fields' => ['guestEmail'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['guestEmail', 'access'],
            'format' => '%s <span class="label-info">[%s]</span>',
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},guestEmail,guest,access;{member_legend},member',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        // The owner of the class book
        'member' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_member.username',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        // The member the class book is shared with, 0 as long as the address has no account
        'guest' => [
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_member.username',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        // The address the owner has entered, in lower case
        'guestEmail' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'rgxp' => 'email', 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'access' => [
            'filter' => true,
            'inputType' => 'select',
            'options' => ['read', 'write'],
            'reference' => &$GLOBALS['TL_LANG']['tl_cb_share']['accessOptions'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(8) NOT NULL default 'read'",
        ],
    ],
];
