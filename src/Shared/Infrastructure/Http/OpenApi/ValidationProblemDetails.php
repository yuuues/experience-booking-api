<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Forma de un error 400: el mismo problem+json de {@see ProblemDetails} más `errors[]`,
 * una entrada por cada violación de Symfony Validator sobre el DTO de la petición.
 *
 * Documentación pura: solo sirve de esquema reutilizable para las respuestas 400.
 */
#[OA\Schema(schema: 'ValidationProblemDetails', title: 'ValidationProblemDetails')]
final readonly class ValidationProblemDetails
{
    public function __construct(
        #[OA\Property(description: 'Identificador estable del problema.', example: '/problems/validation-failed')]
        public string $type,
        #[OA\Property(description: 'Título legible del problema.', example: 'Validation failed')]
        public string $title,
        #[OA\Property(description: 'Código de estado HTTP, siempre 400 en este caso.', example: 400)]
        public int $status,
        #[OA\Property(description: 'Explicación general del problema.', example: 'The request payload is invalid.')]
        public string $detail,
        /** @var list<ValidationError> */
        #[OA\Property(description: 'Una entrada por cada campo del cuerpo que no ha pasado la validación.')]
        public array $errors,
    ) {}
}
