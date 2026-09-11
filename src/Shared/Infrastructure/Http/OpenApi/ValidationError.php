<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\OpenApi;

use OpenApi\Attributes as OA;

/** Una violación individual dentro de `errors[]` en una respuesta 400 de validación. */
#[OA\Schema(schema: 'ValidationError', title: 'ValidationError')]
final readonly class ValidationError
{
    public function __construct(
        #[OA\Property(description: 'Nombre del campo del DTO de entrada que no valida.', example: 'userId')]
        public string $field,
        #[OA\Property(description: 'Mensaje de validación asociado a ese campo.', example: 'This is not a valid UUID.')]
        public string $message,
    ) {}
}
