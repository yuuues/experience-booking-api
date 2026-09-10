<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\Capacity;
use App\Shared\Infrastructure\Doctrine\Type\IntValueObjectType;

/** @extends IntValueObjectType<Capacity> */
final class CapacityType extends IntValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Capacity::class;
    }
}
