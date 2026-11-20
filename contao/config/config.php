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

/*
 * Back end modules (emergency access, the data is managed in the frontend app)
 */
$GLOBALS['BE_MOD']['class_book'] = [
    'cb_school_years' => [
        'tables' => ['tl_cb_school_year'],
    ],
    'cb_holidays' => [
        'tables' => ['tl_cb_holiday'],
    ],
    'cb_periods' => [
        'tables' => ['tl_cb_period'],
    ],
    'cb_subjects' => [
        'tables' => ['tl_cb_subject'],
    ],
    'cb_groups' => [
        'tables' => ['tl_cb_group'],
    ],
    'cb_students' => [
        'tables' => ['tl_cb_student'],
    ],
];
