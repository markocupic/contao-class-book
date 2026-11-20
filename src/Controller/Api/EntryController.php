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

use Markocupic\ContaoClassBook\Exception\RecordNotFoundException;
use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\EntryService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The entries of the logged in member.
 *
 * - save: create or update an entry ({id, data})
 * - done: mark a task as done or not done ({id, done})
 * - delete: delete an entry ({id})
 * - open: the tasks before today that are not done
 */
#[AsController]
#[Route('/_class_book/api/entries/{action}', name: self::ROUTE, requirements: ['action' => 'save|done|delete|open'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class EntryController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_entries';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly EntryService $entries,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $action, Request $request): JsonResponse
    {
        $memberId = $this->authenticator->getMemberId($request);

        if (null === $memberId) {
            return $this->unauthorized();
        }

        if ('open' === $action) {
            return $this->json(['entries' => $this->entries->getOpen($memberId, $this->clock->now()->format('Y-m-d'))]);
        }

        $payload = $this->getPayload($request);
        $id = $payload['id'] ?? null;

        if (null !== $id && !\is_int($id)) {
            return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ('save' === $action) {
                $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];

                return $this->json(['entry' => $this->entries->save($memberId, $id, $data)]);
            }

            if (null === $id) {
                return $this->json(['error' => 'badRequest'], Response::HTTP_BAD_REQUEST);
            }

            if ('done' === $action) {
                return $this->json(['entry' => $this->entries->setDone($memberId, $id, true === ($payload['done'] ?? null))]);
            }

            $this->entries->delete($memberId, $id);

            return $this->json(['deleted' => $id]);
        } catch (ValidationException $exception) {
            return $this->json(['error' => 'validation', 'errors' => $exception->getErrors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RecordNotFoundException) {
            return $this->json(['error' => 'notFound'], Response::HTTP_NOT_FOUND);
        }
    }
}
