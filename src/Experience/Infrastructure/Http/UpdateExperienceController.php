<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Update\UpdateExperienceCommand;
use App\Experience\Application\Update\UpdateExperienceHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateExperienceController
{
    public function __construct(private UpdateExperienceHandler $handler) {}

    #[Route('/api/experiences/{id}', name: 'api_experiences_update', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        UpdateExperienceRequest $request,
    ): JsonResponse {
        return new JsonResponse(($this->handler)(new UpdateExperienceCommand($id, $request->title, $request->description)));
    }
}
