<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingId;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use App\Shared\Infrastructure\Http\OpenApi\ValidationProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BookSeatsController
{
    public function __construct(
        private BookSeatsHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {}

    #[Route('/api/sessions/{sessionId}/bookings', name: 'api_bookings_book', methods: ['POST'], requirements: ['sessionId' => '[0-9a-fA-F-]{36}'])]
    #[Operation([
        'summary' => 'Reservar plazas',
        'description' => 'Reserva un número de plazas en una sesión para un usuario. La referencia pública (`BK-XXXXXXXX`) se '
            . 'genera en el servidor. La fila de la sesión se bloquea mientras dura la operación para evitar sobreventa '
            . 'bajo concurrencia; si el bloqueo no se obtiene a tiempo, la respuesta es 503.',
        'tags' => ['Bookings'],
    ])]
    #[OA\Response(
        response: 201,
        description: 'Reserva confirmada. La cabecera `Location` apunta a `GET /api/bookings/{reference}`.',
        content: new Model(type: BookingResponse::class),
    )]
    #[OA\Response(
        response: 400,
        description: 'El cuerpo de la petición no valida (`userId` no es un UUID, `seats` no es positivo).',
        content: new OA\JsonContent(ref: new Model(type: ValidationProblemDetails::class), example: [
            'type' => '/problems/validation-failed', 'title' => 'Validation failed', 'status' => 400,
            'detail' => 'The request payload is invalid.',
            'errors' => [
                ['field' => 'userId', 'message' => 'This is not a valid UUID.'],
                ['field' => 'seats', 'message' => 'This value should be positive.'],
            ],
        ]),
    )]
    #[OA\Response(
        response: 404,
        description: 'No existe ninguna sesión con ese id.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/session-not-found', 'title' => 'Session not found', 'status' => 404,
            'detail' => 'Session <01a08d39-45ac-73e7-af33-ceed542a59b5> not found.',
        ]),
    )]
    #[OA\Response(
        response: 409,
        description: 'No se ha podido generar una referencia de reserva única tras varios intentos (caso excepcional, reintentable).',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/booking-reference-exhausted', 'title' => 'Booking reference exhausted', 'status' => 409,
            'detail' => 'Could not generate a unique booking reference after 5 attempt(s).',
        ]),
    )]
    #[OA\Response(
        response: 422,
        description: 'La sesión ya ha empezado, o no quedan plazas suficientes.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/not-enough-seats-available', 'title' => 'Not enough seats available', 'status' => 422,
            'detail' => 'Session <01a08d39-45ac-73e7-af33-ceed542a59b5> has 12 seats available, 99 requested.',
        ]),
    )]
    #[OA\Response(
        response: 503,
        description: 'La fila de la sesión está bloqueada por otra reserva concurrente; reintentar (ver `Retry-After`).',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/lock-timeout', 'title' => 'Lock timeout', 'status' => 503,
            'detail' => 'The resource is busy, please retry.',
        ]),
    )]
    public function __invoke(
        string $sessionId,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        BookSeatsRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new BookSeatsCommand(
            bookingId: BookingId::generate()->value,
            sessionId: $sessionId,
            userId: $request->userId,
            seats: $request->seats,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_bookings_find', ['reference' => $response->reference]),
        ]);
    }
}
