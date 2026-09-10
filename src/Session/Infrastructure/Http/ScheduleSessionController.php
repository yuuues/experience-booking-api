<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Schedule\ScheduleSessionCommand;
use App\Session\Application\Schedule\ScheduleSessionHandler;
use App\Session\Domain\SessionId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ScheduleSessionController
{
    public function __construct(
        private ScheduleSessionHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {}

    #[Route('/api/experiences/{experienceId}/sessions', name: 'api_sessions_schedule', methods: ['POST'], requirements: ['experienceId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $experienceId,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        ScheduleSessionRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new ScheduleSessionCommand(
            id: SessionId::generate()->value,
            experienceId: $experienceId,
            startsAt: $request->startsAt,
            capacity: $request->capacity,
            priceAmount: $request->price->amount,
            priceCurrency: $request->price->currency,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_sessions_find', ['id' => $response->id]),
        ]);
    }
}
