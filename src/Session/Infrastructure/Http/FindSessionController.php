<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Find\FindSessionHandler;
use App\Session\Application\Find\FindSessionQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindSessionController
{
    public function __construct(private FindSessionHandler $handler) {}

    #[Route('/api/sessions/{id}', name: 'api_sessions_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindSessionQuery($id)));
    }
}
