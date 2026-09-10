<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Find\FindBookingHandler;
use App\Booking\Application\Find\FindBookingQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindBookingController
{
    public const string REFERENCE_REQUIREMENT = 'BK-[0-9A-Za-z]{8}';

    public function __construct(private FindBookingHandler $handler) {}

    #[Route('/api/bookings/{reference}', name: 'api_bookings_find', methods: ['GET'], requirements: ['reference' => self::REFERENCE_REQUIREMENT])]
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindBookingQuery($reference)));
    }
}
