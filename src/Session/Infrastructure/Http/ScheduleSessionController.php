<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Schedule\ScheduleSessionCommand;
use App\Session\Application\Schedule\ScheduleSessionHandler;
use App\Session\Application\SessionResponse;
use App\Session\Domain\SessionId;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use App\Shared\Infrastructure\Http\OpenApi\ValidationProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
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
    #[Operation([
        'summary' => 'Programar una sesión',
        'description' => 'Crea una sesión (fecha, aforo, precio por plaza) para una experiencia existente. Como mucho una sesión '
            . 'por experiencia y día natural. `startsAt` acepta cualquier instante ISO 8601 con zona explícita '
            . '(`Z` o un offset numérico) y se devuelve normalizado a UTC.',
        'tags' => ['Sessions'],
    ])]
    #[OA\Response(
        response: 201,
        description: 'Sesión creada. La cabecera `Location` apunta a `GET /api/sessions/{id}`.',
        content: new Model(type: SessionResponse::class),
    )]
    #[OA\Response(
        response: 400,
        description: 'Dos causas posibles, distinguibles por si el cuerpo trae `errors[]`: (1) el cuerpo de la '
            . 'petición no valida (fecha sin zona horaria explícita, aforo no positivo, precio negativo o divisa '
            . 'no ISO 4217) — `errors[]` presente; (2) `experienceId` en la URL encaja con el patrón de la ruta '
            . 'pero no es un UUID bien formado — sin `errors[]`.',
        content: new OA\JsonContent(oneOf: [
            new OA\Schema(ref: new Model(type: ValidationProblemDetails::class)),
            new OA\Schema(ref: new Model(type: ProblemDetails::class)),
        ], example: [
            'type' => '/problems/validation-failed', 'title' => 'Validation failed', 'status' => 400,
            'detail' => 'The request payload is invalid.',
            'errors' => [['field' => 'capacity', 'message' => 'This value should be positive.']],
        ]),
    )]
    #[OA\Response(
        response: 404,
        description: 'No existe ninguna experiencia con ese id.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/experience-not-found', 'title' => 'Experience not found', 'status' => 404,
            'detail' => 'Experience <01a08d39-1fef-7536-be5f-fedc3a90af56> not found.',
        ]),
    )]
    #[OA\Response(
        response: 409,
        description: 'La experiencia ya tiene una sesión programada ese mismo día.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/session-already-scheduled-for-day', 'title' => 'Session already scheduled for day', 'status' => 409,
            'detail' => 'Experience <01a08d39-1fef-7536-be5f-fedc3a90af56> already has a session on 2026-11-14.',
        ]),
    )]
    #[OA\Response(
        response: 422,
        description: '`startsAt` está en el pasado.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/session-in-the-past', 'title' => 'Session in the past', 'status' => 422,
            'detail' => 'Cannot schedule a session at 2020-01-01T10:00:00+00:00: it is in the past.',
        ]),
    )]
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
