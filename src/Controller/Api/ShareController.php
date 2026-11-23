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
use Contao\StringUtil;
use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Security\PasswordChecker;
use Markocupic\ContaoClassBook\Service\ShareService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Share the own class book with other members and switch to a class book that
 * was shared with the member.
 *
 * All actions belong to the logged in member, also while the app shows the class
 * book of somebody else. To share the class book the member has to enter the password again.
 */
#[AsController]
#[Route('/_class_book/api/share/{action}', name: self::ROUTE, requirements: ['action' => 'list|save|delete|switch'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class ShareController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_share';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly AccessTokenManager $tokenManager,
        private readonly ShareService $shares,
        private readonly PasswordChecker $passwordChecker,
    ) {
    }

    public function __invoke(Request $request, string $action): JsonResponse
    {
        $user = $this->authenticator->getMember($request);

        if (null === $user) {
            return $this->unauthorized();
        }

        $memberId = (int) $user->id;
        $payload = $this->getPayload($request);

        if ('switch' === $action) {
            return $this->switchBook($request, $memberId, $payload['owner'] ?? null);
        }

        if ('save' === $action) {
            $error = $this->save($user, $payload);

            if (null !== $error) {
                return $error;
            }
        }

        if ('delete' === $action && (!\is_int($payload['id'] ?? null) || !$this->shares->revoke($memberId, $payload['id']))) {
            return $this->json(['error' => 'notFound'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->getList($memberId));
    }

    /**
     * @return array{granted: list<array<string, mixed>>, received: list<array<string, mixed>>}
     */
    private function getList(int $memberId): array
    {
        return [
            'granted' => $this->shares->getGranted($memberId),
            'received' => array_map(
                static fn (array $share): array => [
                    'id' => $share['id'],
                    'owner' => $share['owner'],
                    'name' => trim(StringUtil::decodeEntities($share['firstname'].' '.$share['lastname'])),
                    'access' => $share['access'],
                ],
                $this->shares->getReceived($memberId),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function save(FrontendUser $user, array $payload): JsonResponse|null
    {
        $memberId = (int) $user->id;
        $email = $this->shares->normalizeEmail($payload['email'] ?? null);
        $access = $payload['access'] ?? null;

        $errors = $this->shares->validate($memberId, $email, $access);

        if ([] === $errors) {
            $error = $this->passwordChecker->check($user, $payload['password'] ?? null);

            if (null !== $error) {
                $errors['password'] = $error;
            }
        }

        if ([] !== $errors) {
            return $this->json(['error' => 'validation', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->shares->grant($memberId, $email, (string) $access);

        return null;
    }

    /**
     * A new access token for the class book of the owner, or for the own one.
     */
    private function switchBook(Request $request, int $memberId, mixed $ownerId): JsonResponse
    {
        $elementId = (int) $this->authenticator->getElementId($request);

        if (!\is_int($ownerId) || $ownerId <= 0 || $ownerId === $memberId) {
            return $this->json(['token' => $this->tokenManager->create($memberId, $elementId), 'owner' => null]);
        }

        $access = $this->shares->getAccess($ownerId, $memberId);

        if (null === $access) {
            return $this->json(['error' => 'notFound'], Response::HTTP_NOT_FOUND);
        }

        $name = '';

        foreach ($this->getList($memberId)['received'] as $share) {
            if ($ownerId === $share['owner']) {
                $name = $share['name'];
            }
        }

        return $this->json([
            'token' => $this->tokenManager->create($memberId, $elementId, $ownerId),
            'owner' => ['id' => $ownerId, 'name' => $name, 'access' => $access],
        ]);
    }
}
