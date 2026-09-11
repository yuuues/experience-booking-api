<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Application\Register\RegisterExperienceCommand;
use App\Experience\Application\Register\RegisterExperienceHandler;
use App\Experience\Domain\ExperienceId;
use App\Shared\Infrastructure\Http\OpenApi\ValidationProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
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
    #[Operation([
        'summary' => 'Publicar una experiencia',
        'description' => 'Registra una experiencia nueva a nombre de un proveedor. El `id` se genera en el servidor (UUIDv7).',
        'tags' => ['Experiences'],
    ])]
    #[OA\Response(
        response: 201,
        description: 'Experiencia creada. La cabecera `Location` apunta a `GET /api/experiences/{id}`.',
        content: new Model(type: ExperienceResponse::class),
    )]
    #[OA\Response(
        response: 400,
        description: 'El cuerpo de la petición no valida (título/descripción vacíos o demasiado largos, `providerId` no es un UUID).',
        content: new OA\JsonContent(ref: new Model(type: ValidationProblemDetails::class), example: [
            'type' => '/problems/validation-failed', 'title' => 'Validation failed', 'status' => 400,
            'detail' => 'The request payload is invalid.',
            'errors' => [['field' => 'providerId', 'message' => 'This value should not be blank.']],
        ]),
    )]
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
