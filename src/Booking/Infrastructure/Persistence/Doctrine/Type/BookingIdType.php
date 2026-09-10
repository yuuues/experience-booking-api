<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine\Type;

use App\Booking\Domain\BookingId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class BookingIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return BookingId::class;
    }
}
