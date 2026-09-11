<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Application\Update\UpdateExperienceCommand;
use App\Experience\Application\Update\UpdateExperienceHandler;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use App\Shared\Infrastructure\Http\OpenApi\ValidationProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateExperienceController
{
    public function __construct(private UpdateExperienceHandler $handler) {}

    #[Route('/api/experiences/{id}', name: 'api_experiences_update', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Operation([
        'summary' => 'Actualizar una experiencia',
        'description' => 'Cambia título y descripción. Se rechaza con 409 en cuanto la experiencia tiene alguna reserva confirmada.',
        'tags' => ['Experiences'],
    ])]
    #[OA\Response(
        response: 200,
        description: 'Experiencia actualizada.',
        content: new Model(type: ExperienceResponse::class),
    )]
    #[OA\Response(
        response: 400,
        description: 'El cuerpo de la petición no valida (título o descripción vacíos o demasiado largos).',
        content: new OA\JsonContent(ref: new Model(type: ValidationProblemDetails::class), example: [
            'type' => '/problems/validation-failed', 'title' => 'Validation failed', 'status' => 400,
            'detail' => 'The request payload is invalid.',
            'errors' => [['field' => 'title', 'message' => 'This value should not be blank.']],
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
        description: 'La experiencia ya tiene alguna reserva confirmada y no puede editarse.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/experience-has-bookings', 'title' => 'Experience has bookings', 'status' => 409,
            'detail' => 'Experience <01a08d39-1fef-7536-be5f-fedc3a90af56> cannot be edited because it already has confirmed bookings.',
        ]),
    )]
    public function __invoke(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        UpdateExperienceRequest $request,
    ): JsonResponse {
        return new JsonResponse(($this->handler)(new UpdateExperienceCommand($id, $request->title, $request->description)));
    }
}
