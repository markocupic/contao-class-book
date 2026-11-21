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
 * Normalizes and checks the input of the registration form.
 */
final class RegistrationValidator
{
    public const MIN_PASSWORD_LENGTH = 8;

    // Longer passwords are rejected by the password hasher of Symfony
    public const MAX_PASSWORD_LENGTH = 4096;

    private const MAX_LENGTH = 255;

    public function __construct(private readonly EmailDomainRestriction $domainRestriction)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{firstname: string, lastname: string, email: string, password: string, passwordConfirm: string}
     */
    public function normalize(array $input): array
    {
        $string = static fn (string $key): string => \is_string($input[$key] ?? null) ? $input[$key] : '';

        return [
            'firstname' => trim($string('firstname')),
            'lastname' => trim($string('lastname')),
            'email' => mb_strtolower(trim($string('email'))),
            // A password is taken as it is typed
            'password' => $string('password'),
            'passwordConfirm' => $string('passwordConfirm'),
        ];
    }

    /**
     * @param array{firstname: string, lastname: string, email: string, password: string, passwordConfirm: string} $data
     *
     * @return array<string, string> field => error code
     */
    public function validate(array $data, string $allowedDomains): array
    {
        $errors = [];

        foreach (['firstname', 'lastname', 'email'] as $field) {
            if ('' === $data[$field]) {
                $errors[$field] = 'required';
            } elseif (mb_strlen($data[$field]) > self::MAX_LENGTH) {
                $errors[$field] = 'tooLong';
            }
        }

        if (!isset($errors['email'])) {
            if (false === filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'email';
            } elseif (!$this->domainRestriction->isAllowed($data['email'], $allowedDomains)) {
                $errors['email'] = 'domain';
            }
        }

        if (mb_strlen($data['password']) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'passwordLength';
        } elseif (\strlen($data['password']) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = 'tooLong';
        } elseif ($data['password'] !== $data['passwordConfirm']) {
            $errors['passwordConfirm'] = 'passwordMismatch';
        }

        return $errors;
    }
}
