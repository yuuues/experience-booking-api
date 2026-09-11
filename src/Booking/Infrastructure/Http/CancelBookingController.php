<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\BookingResponse;
use App\Booking\Application\Cancel\CancelBookingCommand;
use App\Booking\Application\Cancel\CancelBookingHandler;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CancelBookingController
{
    public function __construct(private CancelBookingHandler $handler) {}

    #[Route('/api/bookings/{reference}/cancellation', name: 'api_bookings_cancel', methods: ['POST'], requirements: ['reference' => FindBookingController::REFERENCE_REQUIREMENT])]
    #[Operation([
        'summary' => 'Cancelar una reserva',
        'description' => 'Cancela una reserva confirmada y libera sus plazas en la sesión. Igual que en la reserva, la fila de '
            . 'la sesión se bloquea mientras dura la operación; si el bloqueo no se obtiene a tiempo, la respuesta es 503.',
        'tags' => ['Bookings'],
    ])]
    #[OA\Response(
        response: 200,
        description: 'Reserva cancelada (`status: cancelled`, `cancelledAt` con la fecha de cancelación).',
        content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: new Model(type: BookingResponse::class)),
            new OA\Schema(properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'cancelled'], description: 'Estado de la reserva.'),
            ]),
        ]),
    )]
    #[OA\Response(
        response: 400,
        description: 'La referencia tiene un formato inválido: encaja con el patrón de la ruta pero no es una '
            . 'referencia bien formada, por ejemplo porque contiene alguna de las letras ambiguas excluidas '
            . 'del alfabeto (I, L, O, U).',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/invalid-value', 'title' => 'Invalid value', 'status' => 400,
            'detail' => '<BK-IIIIIIII> is not a valid booking reference.',
        ]),
    )]
    #[OA\Response(
        response: 404,
        description: 'No existe ninguna reserva con esa referencia.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/booking-not-found', 'title' => 'Booking not found', 'status' => 404,
            'detail' => 'Booking <BK-00000000> not found.',
        ]),
    )]
    #[OA\Response(
        response: 422,
        description: 'La reserva ya estaba cancelada, o quedan menos de 24 horas para el inicio de la sesión.',
        content: new OA\JsonContent(ref: new Model(type: ProblemDetails::class), example: [
            'type' => '/problems/cancellation-window-closed', 'title' => 'Cancellation window closed', 'status' => 422,
            'detail' => 'Booking <BK-86XY6308> cannot be cancelled: less than 24 hours before the session start (2026-11-14T09:00:00+00:00).',
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
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new CancelBookingCommand($reference)));
    }
}
