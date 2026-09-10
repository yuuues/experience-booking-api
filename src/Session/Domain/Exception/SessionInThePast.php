<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Session\Domain\StartsAt;
use App\Shared\Domain\DomainException;

final class SessionInThePast extends DomainException
{
    public static function at(StartsAt $startsAt): self
    {
        return new self(\sprintf('Cannot schedule a session at %s: it is in the past.', $startsAt->toAtom()));
    }

    public function errorCode(): string
    {
        return 'session-in-the-past';
    }
}
