<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Forma común de todos los errores de la API (RFC 7807 problem+json), sin `errors[]`.
 *
 * Documentación pura: no la instancia ningún controlador, sirve solo de esquema
 * reutilizable para las respuestas 404, 409, 422 y 503 vía `#[Model(type: ProblemDetails::class)]`.
 */
#[OA\Schema(schema: 'ProblemDetails', title: 'ProblemDetails')]
final readonly class ProblemDetails
{
    public function __construct(
        #[OA\Property(description: 'Identificador estable del problema, en kebab-case.', example: '/problems/session-not-found')]
        public string $type,
        #[OA\Property(description: 'Título legible del problema.', example: 'Session not found')]
        public string $title,
        #[OA\Property(description: 'Código de estado HTTP, repetido en el cuerpo.', example: 404)]
        public int $status,
        #[OA\Property(description: 'Explicación específica de esta ocurrencia del problema.', example: 'Session <01a08d39-45ac-73e7-af33-ceed542a59b5> not found.')]
        public string $detail,
    ) {}
}
