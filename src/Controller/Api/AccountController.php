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

namespace Markocupic\ContaoClassBook\Controller\Api;

use Contao\FrontendUser;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\AccountService;
use Markocupic\ContaoClassBook\Service\TwoFactorService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The member deletes the own account with all data of the class book.
 *
 * The member has to enter the password again, even though he or she is logged in.
 */
#[AsController]
#[Route('/_class_book/api/account/{action}', name: self::ROUTE, requirements: ['action' => 'delete'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class AccountController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_account';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly AccountService $accountService,
        private readonly TwoFactorService $twoFactor,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly Security $security,
        // The limiter of the core for password resets: three attempts in 15 minutes
        #[Autowire(service: 'contao.rate_limit.member_password_factory')]
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->authenticator->getMember($request);

        if (null === $user) {
            return $this->unauthorized();
        }

        $password = $this->getPayload($request)['password'] ?? '';
        $limiter = $this->rateLimiterFactory->create('class_book_account_'.$user->id);

        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'validation', 'errors' => ['password' => 'tooMany']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hasher = $this->passwordHasherFactory->getPasswordHasher(FrontendUser::class);

        if (!\is_string($password) || '' === $password || !$hasher->verify((string) $user->password, $password)) {
            return $this->json(['error' => 'validation', 'errors' => ['password' => 'invalid']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $memberId = (int) $user->id;

        // Removes the trusted devices of the two-factor authentication
        $this->twoFactor->disable($user);

        $this->accountService->delete($memberId);

        try {
            $this->security->logout(false);
        } catch (\Throwable) {
            // The member does not exist any more, so the session ends with the next request anyway
        }

        return $this->json(['deleted' => true]);
    }
}
