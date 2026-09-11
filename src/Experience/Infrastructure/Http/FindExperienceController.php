<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Application\Find\FindExperienceHandler;
use App\Experience\Application\Find\FindExperienceQuery;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindExperienceController
{
    public function __construct(private FindExperienceHandler $handler) {}

    #[Route('/api/experiences/{id}', name: 'api_experiences_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Operation([
        'summary' => 'Consultar una experiencia',
        'description' => 'Devuelve una experiencia por su id.',
        'tags' => ['Experiences'],
    ])]
    #[OA\Response(
        response: 200,
        description: 'Experiencia encontrada.',
        content: new Model(type: ExperienceResponse::class),
    )]
    #[OA\Response(
        response: 400,
        description: 'El id tiene un formato inválido: encaja con el patrón de la ruta pero no es un UUID '
            . 'bien formado (por ejemplo, los guiones no están en la posición correcta).',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/invalid-value', 'title' => 'Invalid value', 'status' => 400,
            'detail' => '<aaaaaaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaa> is not a valid UUID.',
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
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindExperienceQuery($id)));
    }
}
