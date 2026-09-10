<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\SessionId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class SessionIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return SessionId::class;
    }
}
