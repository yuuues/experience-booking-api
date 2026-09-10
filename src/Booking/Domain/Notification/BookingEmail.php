<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

/** Everything a mailer needs to render a booking email; no domain objects cross this boundary. */
final readonly class BookingEmail
{
    public const string TYPE_CONFIRMATION = 'booking-confirmation';
    public const string TYPE_CANCELLATION = 'booking-cancellation';

    public function __construct(
        public string $to,
        public string $type,
        public string $reference,
        public int $seats,
        public int $totalAmount,
        public string $totalCurrency,
        public string $sessionStartsAt,
    ) {}
}
