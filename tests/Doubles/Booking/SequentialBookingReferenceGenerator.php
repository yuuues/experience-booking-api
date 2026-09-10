<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;

final class SequentialBookingReferenceGenerator implements BookingReferenceGenerator
{
    private int $counter = 0;

    public function next(): BookingReference
    {
        ++$this->counter;

        return BookingReference::fromString(\sprintf('BK-%08d', $this->counter));
    }
}
