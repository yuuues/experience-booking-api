<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Session\Domain\SessionId;
use App\Shared\Domain\DomainException;

final class NotEnoughSeatsAvailable extends DomainException
{
    public static function for(SessionId $id, int $requested, int $available): self
    {
        return new self(\sprintf('Session <%s> has %d seats available, %d requested.', $id->value, $available, $requested));
    }

    public function errorCode(): string
    {
        return 'not-enough-seats-available';
    }
}
