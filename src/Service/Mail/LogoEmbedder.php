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

namespace Markocupic\ContaoClassBook\Service\Mail;

use Symfony\Component\Mime\Email;

/**
 * Embeds the logo in an e-mail. It travels with the e-mail (content id), so mail
 * programs show it without loading anything from the website. A PNG, because most
 * mail programs do not show SVG.
 */
final class LogoEmbedder
{
    public const CONTENT_ID = 'class-book-logo';

    private const PATH = '/public/images/class-book-logo.png';

    /**
     * @return string|null the content id for the template (img src="cid:…") or null without the file
     */
    public function embed(Email $email): string|null
    {
        $path = \dirname(__DIR__, 3).self::PATH;

        if (!is_file($path)) {
            return null;
        }

        $email->embedFromPath($path, self::CONTENT_ID, 'image/png');

        return self::CONTENT_ID;
    }
}
