<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine\Type;

use App\Booking\Domain\Seats;
use App\Shared\Infrastructure\Doctrine\Type\IntValueObjectType;

/** @extends IntValueObjectType<Seats> */
final class SeatsType extends IntValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Seats::class;
    }
}
