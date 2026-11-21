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
use Markocupic\ContaoClassBook\Service\Registration\RegistrationValidator;
use PHPUnit\Framework\TestCase;

class RegistrationValidatorTest extends TestCase
{
    public function testNormalizesTheInput(): void
    {
        $data = $this->getValidator()->normalize([
            'firstname' => ' Anna ',
            'lastname' => 'Muster',
            'email' => ' Anna.Muster@Example.org ',
            'password' => ' secret 123 ',
            'passwordConfirm' => ['not', 'a', 'string'],
        ]);

        $this->assertSame('Anna', $data['firstname']);
        $this->assertSame('anna.muster@example.org', $data['email']);
        $this->assertSame(' secret 123 ', $data['password']);
        $this->assertSame('', $data['passwordConfirm']);
        $this->assertFalse($data['terms']);
        $this->assertTrue($this->getValidator()->normalize(['terms' => '1'])['terms']);
    }

    public function testAcceptsValidData(): void
    {
        $this->assertSame([], $this->getValidator()->validate($this->getData(), ''));
        $this->assertSame([], $this->getValidator()->validate($this->getData(), 'example.org'));
    }

    public function testReportsMissingAndInvalidValues(): void
    {
        $validator = $this->getValidator();

        $this->assertSame(
            ['firstname' => 'required', 'lastname' => 'required', 'email' => 'required', 'password' => 'passwordLength', 'terms' => 'terms'],
            $validator->validate($validator->normalize([]), ''),
        );

        $this->assertSame(['email' => 'email'], $validator->validate($this->getData(['email' => 'anna@']), ''));
        $this->assertSame(['email' => 'domain'], $validator->validate($this->getData(), 'schule-ettiswil.ch'));
        $this->assertSame(['firstname' => 'tooLong'], $validator->validate($this->getData(['firstname' => str_repeat('a', 256)]), ''));
    }

    public function testChecksThePassword(): void
    {
        $validator = $this->getValidator();

        $this->assertSame(
            ['password' => 'passwordLength'],
            $validator->validate($this->getData(['password' => '1234567', 'passwordConfirm' => '1234567']), ''),
        );

        $this->assertSame(
            ['passwordConfirm' => 'passwordMismatch'],
            $validator->validate($this->getData(['passwordConfirm' => 'something else']), ''),
        );
    }

    public function testRequiresTheTermsOfUse(): void
    {
        $this->assertSame(['terms' => 'terms'], $this->getValidator()->validate($this->getData(['terms' => false]), ''));
    }

    private function getValidator(): RegistrationValidator
    {
        return new RegistrationValidator(new EmailDomainRestriction());
    }

    /**
     * @param array<string, bool|string> $override
     *
     * @return array{firstname: string, lastname: string, email: string, password: string, passwordConfirm: string, terms: bool}
     */
    private function getData(array $override = []): array
    {
        return [
            'firstname' => 'Anna',
            'lastname' => 'Muster',
            'email' => 'anna.muster@example.org',
            'password' => 'correct horse',
            'passwordConfirm' => 'correct horse',
            'terms' => true,
            ...$override,
        ];
    }
}
