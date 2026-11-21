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
use Doctrine\DBAL\Connection;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\AccountService;
use Markocupic\ContaoClassBook\Service\EmailChange\EmailChangeService;
use Markocupic\ContaoClassBook\Service\EmailChange\EmailChangeValidator;
use Markocupic\ContaoClassBook\Service\TwoFactorService;
use Markocupic\ContaoClassBook\Service\UsernameService;
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
 * The account of the member: change the user name or the e-mail address, or delete the
 * account with all data of the class book.
 *
 * To change the address or to delete the account the member has to enter the password again.
 */
#[AsController]
#[Route('/_class_book/api/account/{action}', name: self::ROUTE, requirements: ['action' => 'delete|username|email'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class AccountController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_account';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly AccountService $accountService,
        private readonly UsernameService $usernameService,
        private readonly TwoFactorService $twoFactor,
        private readonly EmailChangeValidator $emailValidator,
        private readonly EmailChangeService $emailChangeService,
        private readonly Connection $connection,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly Security $security,
        // The limiter of the core for password resets: three attempts in 15 minutes
        #[Autowire(service: 'contao.rate_limit.member_password_factory')]
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function __invoke(Request $request, string $action): JsonResponse
    {
        $user = $this->authenticator->getMember($request);

        if (null === $user) {
            return $this->unauthorized();
        }

        if ('username' === $action) {
            return $this->changeUsername($user, $request);
        }

        if ('email' === $action) {
            return $this->changeEmail($user, $request);
        }

        $error = $this->checkPassword($user, $request);

        if (null !== $error) {
            return $error;
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

    /**
     * The password of the member, limited to three attempts in 15 minutes.
     *
     * @return JsonResponse|null the error or null if the password is right
     */
    private function checkPassword(FrontendUser $user, Request $request): JsonResponse|null
    {
        $password = $this->getPayload($request)['password'] ?? '';
        $limiter = $this->rateLimiterFactory->create('class_book_account_'.$user->id);

        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'validation', 'errors' => ['password' => 'tooMany']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hasher = $this->passwordHasherFactory->getPasswordHasher(FrontendUser::class);

        if (!\is_string($password) || '' === $password || !$hasher->verify((string) $user->password, $password)) {
            return $this->json(['error' => 'validation', 'errors' => ['password' => 'invalid']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * Sends a link to the new e-mail address. The address is only changed when the link
     * is opened (see EmailChangeService), so the answer is the same whether the address
     * is free or belongs to somebody else.
     */
    private function changeEmail(FrontendUser $user, Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);
        $memberId = (int) $user->id;
        $email = $this->emailValidator->normalize($payload['email'] ?? null);

        // The domain restriction of the content element the app runs in
        $domains = (string) $this->connection->fetchOne('SELECT cbRegistrationDomains FROM tl_content WHERE id = ?', [(int) $this->authenticator->getElementId($request)]);

        $error = $this->emailValidator->validate($email, $memberId, $domains);

        if (null !== $error) {
            return $this->json(['error' => 'validation', 'errors' => ['email' => $error]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $error = $this->checkPassword($user, $request);

        if (null !== $error) {
            return $error;
        }

        // The link leads to the page of the app. Only a path on this website is accepted.
        $path = $payload['path'] ?? '';
        $path = \is_string($path) && 1 === preg_match('~^/(?!/)[^\\\\?#\s]*$~', $path) ? $path : '/';

        $this->emailChangeService->request($memberId, $email, $request->getSchemeAndHttpHost().$path, $request->getLocale());

        return $this->json(['sent' => true]);
    }

    private function changeUsername(FrontendUser $user, Request $request): JsonResponse
    {
        $username = $this->getPayload($request)['username'] ?? '';
        $username = \is_string($username) ? trim($username) : '';

        $error = $this->usernameService->validate($username, (int) $user->id);

        if (null !== $error) {
            return $this->json(['error' => 'validation', 'errors' => ['username' => $error]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The user object of the session is changed too: Contao reloads the member by the
        // user name with every request, the old name would end the session
        $user->username = $username;
        $user->save();

        return $this->json(['username' => $username]);
    }
}
