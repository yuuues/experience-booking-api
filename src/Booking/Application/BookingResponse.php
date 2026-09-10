<?php

declare(strict_types=1);

namespace App\Booking\Application;

use App\Booking\Domain\Booking;
use App\Session\Application\MoneyResponse;

final readonly class BookingResponse
{
    public function __construct(
        public string $reference,
        public string $sessionId,
        public string $userId,
        public int $seats,
        public MoneyResponse $total,
        public string $status,
        public string $bookedAt,
        public ?string $cancelledAt,
    ) {}

    public static function fromBooking(Booking $booking): self
    {
        return new self(
            $booking->reference()->value,
            $booking->sessionId()->value,
            $booking->userId()->value,
            $booking->seats()->value,
            MoneyResponse::fromMoney($booking->totalPrice()),
            $booking->status()->value,
            $booking->bookedAt()->format(\DATE_ATOM),
            $booking->cancelledAt()?->format(\DATE_ATOM),
        );
    }
}
