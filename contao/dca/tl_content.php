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

$GLOBALS['TL_DCA']['tl_content']['palettes'][ClassBookController::TYPE] = '{type_legend},title,type,headline;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';
