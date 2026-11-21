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

use Markocupic\ContaoClassBook\Controller\ContentElement\ClassBookController;

$GLOBALS['TL_DCA']['tl_content']['palettes'][ClassBookController::TYPE] = '{type_legend},title,type,headline;{class_book_legend},cbStandalone;{class_book_registration_legend},cbRegistration,cbRegistrationDomains;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';

// The app fills the whole page (for a page without the layout of the website)
$GLOBALS['TL_DCA']['tl_content']['fields']['cbStandalone'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

// Teachers can register themselves and activate the account by a link in an e-mail
$GLOBALS['TL_DCA']['tl_content']['fields']['cbRegistration'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

// Allowed domains of the e-mail address, empty: every address may register
$GLOBALS['TL_DCA']['tl_content']['fields']['cbRegistrationDomains'] = [
    'inputType' => 'text',
    'eval' => ['maxlength' => 1024, 'tl_class' => 'clr long'],
    'sql' => ['type' => 'string', 'length' => 1024, 'default' => ''],
];
