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

use Contao\FrontendUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Checks the password a logged in member has to enter again before something
 * critical happens (delete the account, change the e-mail address, share the class book).
 */
final class PasswordChecker
{
    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        // The limiter of the core for password resets: three attempts in 15 minutes
        #[Autowire(service: 'contao.rate_limit.member_password_factory')]
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    /**
     * @return string|null the error code (tooMany, invalid) or null if the password is right
     */
    public function check(FrontendUser $user, mixed $password): string|null
    {
        $limiter = $this->rateLimiterFactory->create('class_book_account_'.$user->id);

        if (!$limiter->consume()->isAccepted()) {
            return 'tooMany';
        }

        $hasher = $this->passwordHasherFactory->getPasswordHasher(FrontendUser::class);

        if (!\is_string($password) || '' === $password || !$hasher->verify((string) $user->password, $password)) {
            return 'invalid';
        }

        // Only wrong passwords count
        $limiter->reset();

        return null;
    }
}
