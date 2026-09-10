<?php

declare(strict_types=1);

namespace App\Booking\Application\Find;

final readonly class FindBookingQuery
{
    public function __construct(public string $reference) {}
}
