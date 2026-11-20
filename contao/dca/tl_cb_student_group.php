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

use Contao\DC_Table;

/*
 * Junction table: the learning groups of a student besides the home class.
 * There is no back end module for this table.
 */
$GLOBALS['TL_DCA']['tl_cb_student_group'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'member' => 'index',
                'student' => 'index',
                'learningGroup' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'member' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'student' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'learningGroup' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
    ],
];
