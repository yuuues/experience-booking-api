<?php

declare(strict_types=1);

namespace App\Booking\Application\Cancel;

final readonly class CancelBookingCommand
{
    public function __construct(public string $reference) {}
}
