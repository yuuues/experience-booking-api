<?php

declare(strict_types=1);

namespace App\Booking\Domain;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
