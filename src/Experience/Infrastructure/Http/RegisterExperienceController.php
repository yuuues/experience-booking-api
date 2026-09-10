<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Register\RegisterExperienceCommand;
use App\Experience\Application\Register\RegisterExperienceHandler;
use App\Experience\Domain\ExperienceId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class RegisterExperienceController
{
    public function __construct(
        private RegisterExperienceHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {}

    #[Route('/api/experiences', name: 'api_experiences_register', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        RegisterExperienceRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new RegisterExperienceCommand(
            id: ExperienceId::generate()->value,
            title: $request->title,
            description: $request->description,
            providerId: $request->providerId,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_experiences_find', ['id' => $response->id]),
        ]);
    }
}
