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

namespace Markocupic\ContaoClassBook\Service\MasterData;

use Markocupic\ContaoClassBook\Exception\ValidationException;

/**
 * Validates and normalizes the data of a master data record.
 */
final class MasterDataValidator
{
    public const ERROR_REQUIRED = 'required';

    public const ERROR_INVALID = 'invalid';

    public const ERROR_TOO_LONG = 'tooLong';

    public const ERROR_OUT_OF_RANGE = 'outOfRange';

    public const ERROR_BEFORE_START = 'beforeStart';

    public const ERROR_NOT_AFTER_START = 'notAfterStart';

    public const ERROR_UNKNOWN_REFERENCE = 'unknownReference';

    /**
     * @param array<string, mixed>           $input
     * @param callable(string, int): bool    $referenceExists Receives the resource and the id
     *
     * @return array<string, int|string> The normalized values of all fields
     *
     * @throws ValidationException
     */
    public function validate(string $resource, array $input, callable $referenceExists): array
    {
        $fields = ResourceDefinitions::get($resource)['fields'];
        $values = [];
        $errors = [];

        foreach ($fields as $name => $config) {
            $raw = $input[$name] ?? '';
            $value = \is_string($raw) || \is_int($raw) ? trim((string) $raw) : '';

            if ('' === $value) {
                if ($config['required'] ?? false) {
                    $errors[$name] = self::ERROR_REQUIRED;
                }

                $values[$name] = \in_array($config['type'], ['integer', 'reference'], true) ? 0 : '';

                continue;
            }

            $error = $this->validateValue($value, $config, $referenceExists);

            if (null !== $error) {
                $errors[$name] = $error;

                continue;
            }

            $values[$name] = match ($config['type']) {
                'integer', 'reference' => (int) $value,
                'color' => strtolower($value),
                default => $value,
            };
        }

        // Rules that compare two fields
        foreach ($fields as $name => $config) {
            if (isset($errors[$name])) {
                continue;
            }

            $notBefore = $config['notBefore'] ?? null;

            if (null !== $notBefore && !isset($errors[$notBefore]) && $values[$name] < $values[$notBefore]) {
                $errors[$name] = self::ERROR_BEFORE_START;
            }

            $after = $config['after'] ?? null;

            if (null !== $after && !isset($errors[$after]) && $values[$name] <= $values[$after]) {
                $errors[$name] = self::ERROR_NOT_AFTER_START;
            }
        }

        if ([] !== $errors) {
            throw new ValidationException($errors);
        }

        return $values;
    }

    /**
     * @param array<string, mixed>        $config
     * @param callable(string, int): bool $referenceExists
     */
    private function validateValue(string $value, array $config, callable $referenceExists): string|null
    {
        switch ($config['type']) {
            case 'string':
                if (mb_strlen($value) > $config['maxLength']) {
                    return self::ERROR_TOO_LONG;
                }

                return null;

            case 'integer':
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    return self::ERROR_INVALID;
                }

                if ((int) $value < $config['min'] || (int) $value > $config['max']) {
                    return self::ERROR_OUT_OF_RANGE;
                }

                return null;

            case 'date':
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                return false === $date || $date->format('Y-m-d') !== $value ? self::ERROR_INVALID : null;

            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : self::ERROR_INVALID;

            case 'color':
                return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? null : self::ERROR_INVALID;

            case 'reference':
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    return self::ERROR_INVALID;
                }

                return $referenceExists($config['resource'], (int) $value) ? null : self::ERROR_UNKNOWN_REFERENCE;
        }

        throw new \LogicException(\sprintf('Unknown field type "%s".', $config['type']));
    }
}
