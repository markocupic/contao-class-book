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

use Markocupic\ContaoClassBook\Exception\RecordInUseException;
use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataService;
use Markocupic\ContaoClassBook\Service\MasterData\ResourceDefinitions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Master data of the logged in member: school years, holidays, periods,
 * subjects, learning groups and students.
 *
 * - list: all records of all resources and the field definitions
 * - save: create or update a record ({resource, id, data})
 * - delete: delete a record ({resource, id})
 */
#[AsController]
#[Route('/_class_book/api/master-data/{action}', name: self::ROUTE, requirements: ['action' => 'list|save|delete'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class MasterDataController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_master_data';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly MasterDataService $masterData,
    ) {
    }

    public function __invoke(string $action, Request $request): JsonResponse
    {
        $memberId = $this->authenticator->getMemberId($request);

        if (null === $memberId) {
            return $this->unauthorized();
        }

        if ('list' === $action) {
            return $this->json([
                'schema' => ResourceDefinitions::getSchema(),
                'data' => $this->masterData->getAll($memberId),
            ]);
        }

        $payload = $this->getPayload($request);
        $resource = $payload['resource'] ?? null;
        $id = $payload['id'] ?? null;

        if (!\is_string($resource) || !ResourceDefinitions::has($resource) || (null !== $id && !\is_int($id))) {
            return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ('save' === $action) {
                $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];

                return $this->json(['record' => $this->masterData->save($memberId, $resource, $id, $data)]);
            }

            if (null === $id) {
                return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
            }

            $this->masterData->delete($memberId, $resource, $id);

            return $this->json(['deleted' => $id]);
        } catch (ValidationException $exception) {
            return $this->json(['error' => 'validation', 'errors' => $exception->getErrors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RecordNotFoundException) {
            return $this->json(['error' => 'notFound'], Response::HTTP_NOT_FOUND);
        } catch (RecordInUseException) {
            return $this->json(['error' => 'inUse'], Response::HTTP_CONFLICT);
        }
    }
}
