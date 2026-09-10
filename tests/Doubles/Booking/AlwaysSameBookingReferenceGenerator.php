<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;

/**
 * Always yields the same reference. Combined with a repository that already reports that
 * reference as taken, this forces BookSeatsHandler's uniqueness loop to exhaust its attempt
 * budget, so the BookingReferenceExhausted path can be exercised deterministically.
 */
final class AlwaysSameBookingReferenceGenerator implements BookingReferenceGenerator
{
    public int $calls = 0;

    public function __construct(private readonly BookingReference $reference) {}

    public function next(): BookingReference
    {
        ++$this->calls;

        return $this->reference;
    }
}
