<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Booking\Domain\BookingReference;

/** Makes email handlers idempotent under at-least-once delivery. */
interface SentNotificationRegistry
{
    public function wasSent(BookingReference $reference, string $type): bool;

    public function markSent(BookingReference $reference, string $type): void;
}
