<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine\Type;

use App\Booking\Domain\UserId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class UserIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return UserId::class;
    }
}
