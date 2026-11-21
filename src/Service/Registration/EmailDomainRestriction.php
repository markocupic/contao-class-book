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
 * Restricts the registration to e-mail addresses of certain domains.
 *
 * The list comes from the content element: domains separated by commas, semicolons,
 * spaces or line breaks, with or without a leading @. An empty list allows every domain.
 */
final class EmailDomainRestriction
{
    /**
     * @return list<string> the domains in lower case, without the @
     */
    public function parse(string $list): array
    {
        $domains = [];

        foreach (preg_split('/[\s,;]+/', mb_strtolower($list)) ?: [] as $domain) {
            $domain = trim(ltrim($domain, '@'), '.');

            if ('' !== $domain) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * The domain has to match exactly: a subdomain of an allowed domain is not allowed.
     */
    public function isAllowed(string $email, string $list): bool
    {
        $domains = $this->parse($list);

        if ([] === $domains) {
            return true;
        }

        $position = strrpos($email, '@');

        if (false === $position) {
            return false;
        }

        return \in_array(mb_strtolower(substr($email, $position + 1)), $domains, true);
    }
}
