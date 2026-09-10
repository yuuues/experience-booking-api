<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine\Type;

use App\Booking\Domain\BookingReference;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;

/** @extends StringValueObjectType<BookingReference> */
final class BookingReferenceType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return BookingReference::class;
    }
}
