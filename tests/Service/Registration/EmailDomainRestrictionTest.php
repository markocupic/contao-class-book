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

namespace Markocupic\ContaoClassBook\Tests\Service\Registration;

use Markocupic\ContaoClassBook\Service\Registration\EmailDomainRestriction;
use PHPUnit\Framework\TestCase;

class EmailDomainRestrictionTest extends TestCase
{
    public function testParsesTheListOfDomains(): void
    {
        $restriction = new EmailDomainRestriction();

        $this->assertSame(
            ['schule-ettiswil.ch', 'schule-grosswangen.ch', 'example.org'],
            $restriction->parse("@Schule-Ettiswil.ch, schule-grosswangen.ch;\n example.org  schule-ettiswil.ch"),
        );

        $this->assertSame([], $restriction->parse(" , \n"));
    }

    public function testAllowsEveryAddressWithoutDomains(): void
    {
        $restriction = new EmailDomainRestriction();

        $this->assertTrue($restriction->isAllowed('anna@example.org', ''));
        $this->assertTrue($restriction->isAllowed('anna@example.org', '  '));
    }

    public function testAllowsOnlyTheListedDomains(): void
    {
        $restriction = new EmailDomainRestriction();
        $domains = '@schule-ettiswil.ch, @schule-grosswangen.ch';

        $this->assertTrue($restriction->isAllowed('anna@schule-ettiswil.ch', $domains));
        $this->assertTrue($restriction->isAllowed('Anna@Schule-Grosswangen.CH', $domains));
        $this->assertFalse($restriction->isAllowed('anna@example.org', $domains));

        // Neither a subdomain nor a domain that only ends the same way
        $this->assertFalse($restriction->isAllowed('anna@mail.schule-ettiswil.ch', $domains));
        $this->assertFalse($restriction->isAllowed('anna@andere-schule-ettiswil.ch', $domains));
        $this->assertFalse($restriction->isAllowed('anna@schule-ettiswil.ch.example.org', $domains));
        $this->assertFalse($restriction->isAllowed('no-address', $domains));
    }
}
