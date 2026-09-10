<?php

declare(strict_types=1);

namespace App\Booking\Domain;

interface BookingReferenceGenerator
{
    public function next(): BookingReference;
}
