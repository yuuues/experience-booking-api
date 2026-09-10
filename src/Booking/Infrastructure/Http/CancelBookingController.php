<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Cancel\CancelBookingCommand;
use App\Booking\Application\Cancel\CancelBookingHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CancelBookingController
{
    public function __construct(private CancelBookingHandler $handler) {}

    #[Route('/api/bookings/{reference}/cancellation', name: 'api_bookings_cancel', methods: ['POST'], requirements: ['reference' => FindBookingController::REFERENCE_REQUIREMENT])]
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new CancelBookingCommand($reference)));
    }
}
