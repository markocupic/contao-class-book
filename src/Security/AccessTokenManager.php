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

namespace Markocupic\ContaoClassBook\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates and validates the signed access token of the frontend app.
 *
 * A new token is created with every page view. It binds the API requests
 * to the member and to the frontend module the page was rendered for.
 */
final class AccessTokenManager
{
    public const TTL = 43200;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(int $memberId, int $moduleId): string
    {
        $payload = $this->encode(json_encode(
            [
                'm' => $memberId,
                'mod' => $moduleId,
                'exp' => $this->clock->now()->getTimestamp() + self::TTL,
            ],
            JSON_THROW_ON_ERROR,
        ));

        return $payload.'.'.$this->sign($payload);
    }

    public function validate(string $token): AccessToken|null
    {
        $parts = explode('.', $token);

        if (2 !== \count($parts) || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);

        if (!\is_array($data) || !\is_int($data['m'] ?? null) || !\is_int($data['mod'] ?? null) || !\is_int($data['exp'] ?? null)) {
            return null;
        }

        if ($data['exp'] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return new AccessToken($data['m'], $data['mod'], $data['exp']);
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', 'class-book-token:'.$payload, $this->secret, true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
