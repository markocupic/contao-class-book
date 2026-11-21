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

use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\TwoFactorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two-factor authentication of the member: status, setup with the QR code,
 * enable with a code of the authenticator app, disable and new backup codes.
 *
 * Changes require that the member has logged in with the password in this session.
 */
#[AsController]
#[Route('/_class_book/api/two-factor/{action}', name: self::ROUTE, requirements: ['action' => 'status|setup|enable|disable|backup-codes'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class TwoFactorController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_two_factor';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function __invoke(Request $request, string $action): JsonResponse
    {
        $user = $this->authenticator->getMember($request);

        if (null === $user) {
            return $this->unauthorized();
        }

        if ('status' === $action) {
            return $this->json($this->twoFactor->getStatus($user));
        }

        if (!$this->authenticator->isFullyAuthenticated()) {
            return $this->json(['error' => 'reauthenticate'], Response::HTTP_FORBIDDEN);
        }

        $result = [];

        switch ($action) {
            case 'setup':
                $setup = $this->twoFactor->setup($user, $request);

                if (null === $setup) {
                    return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
                }

                $result = ['setup' => $setup];
                break;

            case 'enable':
                $code = $this->getPayload($request)['code'] ?? '';

                if (!\is_string($code) || !$this->twoFactor->enable($user, $code)) {
                    return $this->json(['error' => 'validation', 'errors' => ['code' => 'invalidCode']], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                break;

            case 'disable':
                $this->twoFactor->disable($user);
                break;

            case 'backup-codes':
                $codes = $this->twoFactor->generateBackupCodes($user);

                if (null === $codes) {
                    return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
                }

                $result = ['codes' => $codes];
                break;
        }

        return $this->json([...$this->twoFactor->getStatus($user), ...$result]);
    }
}
