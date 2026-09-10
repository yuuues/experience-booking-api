<?php

declare(strict_types=1);

namespace App\Booking\Application\Book;

final readonly class BookSeatsCommand
{
    public function __construct(
        public string $bookingId,
        public string $sessionId,
        public string $userId,
        public int $seats,
    ) {}
}
