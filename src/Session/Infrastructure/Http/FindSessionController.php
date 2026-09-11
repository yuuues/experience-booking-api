<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Find\FindSessionHandler;
use App\Session\Application\Find\FindSessionQuery;
use App\Session\Application\SessionResponse;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindSessionController
{
    public function __construct(private FindSessionHandler $handler) {}

    #[Route('/api/sessions/{id}', name: 'api_sessions_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Operation([
        'summary' => 'Consultar una sesión',
        'description' => 'Devuelve una sesión por su id, incluidas las plazas disponibles en este momento.',
        'tags' => ['Sessions'],
    ])]
    #[OA\Response(
        response: 200,
        description: 'Sesión encontrada.',
        content: new Model(type: SessionResponse::class),
    )]
    #[OA\Response(
        response: 404,
        description: 'No existe ninguna sesión con ese id.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/session-not-found', 'title' => 'Session not found', 'status' => 404,
            'detail' => 'Session <01a08d39-45ac-73e7-af33-ceed542a59b5> not found.',
        ]),
    )]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindSessionQuery($id)));
    }
}
