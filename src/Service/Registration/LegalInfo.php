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

namespace Markocupic\ContaoClassBook\Service\Registration;

/**
 * The legal pages of the app (imprint, terms of use, privacy policy).
 *
 * The texts are Twig templates (contao/templates/twig/legal). A member accepts the
 * terms when registering: tl_member.cbTermsAccepted holds the time, cbTermsVersion
 * the version of the terms.
 */
final class LegalInfo
{
    // The date of the terms of use. Raise it whenever their meaning changes
    public const VERSION = '2026-11-21';

    public const IMPRINT = 'imprint';

    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const PAGES = [self::IMPRINT, self::TERMS, self::PRIVACY];

    // Query parameter that shows a legal page instead of the login form or the app
    public const PARAMETER = 'info';

    public static function getTemplate(string $page): string
    {
        return \sprintf('@Contao/legal/class_book_%s.html.twig', $page);
    }

    /**
     * The version as a date for readers, e.g. "21.11.2026".
     */
    public static function getVersionDate(): string
    {
        return (new \DateTimeImmutable(self::VERSION))->format('d.m.Y');
    }
}
