<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Notification\SentNotificationRegistry;

final class InMemorySentNotificationRegistry implements SentNotificationRegistry
{
    /** @var array<string, true> */
    private array $sent = [];

    public function wasSent(BookingReference $reference, string $type): bool
    {
        return isset($this->sent[$reference->value . '|' . $type]);
    }

    public function markSent(BookingReference $reference, string $type): void
    {
        $this->sent[$reference->value . '|' . $type] = true;
    }
}
