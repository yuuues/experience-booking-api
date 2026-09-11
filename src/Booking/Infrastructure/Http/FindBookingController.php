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
        content: new Model(type: BookingResponse::class),
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
