<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\BookingResponse;
use App\Booking\Application\Find\FindBookingHandler;
use App\Booking\Application\Find\FindBookingQuery;
use App\Shared\Infrastructure\Http\OpenApi\ProblemDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Operation;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindBookingController
{
    public const string REFERENCE_REQUIREMENT = 'BK-[0-9A-Za-z]{8}';

    public function __construct(private FindBookingHandler $handler) {}

    #[Route('/api/bookings/{reference}', name: 'api_bookings_find', methods: ['GET'], requirements: ['reference' => self::REFERENCE_REQUIREMENT])]
    #[Operation([
        'summary' => 'Consultar una reserva',
        'description' => 'Devuelve una reserva por su referencia pública (`BK-XXXXXXXX`).',
        'tags' => ['Bookings'],
    ])]
    #[OA\Response(
        response: 200,
        description: 'Reserva encontrada.',
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
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindBookingQuery($reference)));
    }
}
