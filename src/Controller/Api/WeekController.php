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
use Markocupic\ContaoClassBook\Service\TimetableService;
use Markocupic\ContaoClassBook\Service\WeekCalculator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns the week a date belongs to and its timetable. Without a date the
 * current week is returned.
 *
 * The Contao request token check is disabled, the request is protected by the
 * signed access token instead (see ApiAuthenticator).
 */
#[AsController]
#[Route('/_class_book/api/week', name: self::ROUTE, defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['POST'])]
final class WeekController extends AbstractApiController
{
    public const ROUTE = 'markocupic_contao_class_book_api_week';

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly WeekCalculator $weekCalculator,
        private readonly TimetableService $timetable,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $memberId = $this->authenticator->getMemberId($request);

        if (null === $memberId) {
            return $this->unauthorized();
        }

        $data = $this->getPayload($request);
        $today = $this->clock->now();
        $date = $today;

        if (\is_string($data['date'] ?? null) && '' !== $data['date']) {
            $date = $this->weekCalculator->parseDate($data['date']);

            if (null === $date) {
                return $this->json(['error' => 'invalid_date'], Response::HTTP_BAD_REQUEST);
            }
        }

        $week = $this->weekCalculator->getWeek($date, $today);

        return $this->json([
            'week' => $week,
            'timetable' => $this->timetable->getWeek($memberId, array_column($week['days'], 'date')),
        ]);
    }
}
