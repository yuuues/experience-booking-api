<?php

declare(strict_types=1);

namespace App\Booking\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class BookingCancelled implements DomainEvent
{
    public function __construct(
        public string $reference,
        public string $bookingId,
        public string $sessionId,
        public string $userId,
        public int $seats,
        private DateTimeImmutable $occurredOn,
    ) {}

    public function aggregateId(): string
    {
        return $this->bookingId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'booking.cancelled';
    }
}
