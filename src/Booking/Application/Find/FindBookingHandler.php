<?php

declare(strict_types=1);

namespace App\Booking\Application\Find;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Exception\BookingNotFound;

final readonly class FindBookingHandler
{
    public function __construct(private BookingRepository $bookings) {}

    public function __invoke(FindBookingQuery $query): BookingResponse
    {
        $reference = BookingReference::fromString($query->reference);
        $booking = $this->bookings->findByReference($reference) ?? throw BookingNotFound::withReference($reference);

        return BookingResponse::fromBooking($booking);
    }
}
