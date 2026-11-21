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

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// When the member has accepted the terms of use of the class book (0: never) and which version
$GLOBALS['TL_DCA']['tl_member']['fields']['cbTermsAccepted'] = [
    'inputType' => 'text',
    'eval' => ['rgxp' => 'datim', 'readonly' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

$GLOBALS['TL_DCA']['tl_member']['fields']['cbTermsVersion'] = [
    'inputType' => 'text',
    'eval' => ['maxlength' => 16, 'readonly' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
];

PaletteManipulator::create()
    ->addLegend('class_book_legend', 'login_legend', PaletteManipulator::POSITION_AFTER, true)
    ->addField(['cbTermsAccepted', 'cbTermsVersion'], 'class_book_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_member')
;
