<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Find\FindExperienceHandler;
use App\Experience\Application\Find\FindExperienceQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindExperienceController
{
    public function __construct(private FindExperienceHandler $handler) {}

    #[Route('/api/experiences/{id}', name: 'api_experiences_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindExperienceQuery($id)));
    }
}
