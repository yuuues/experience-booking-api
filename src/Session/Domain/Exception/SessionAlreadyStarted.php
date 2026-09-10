<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Session\Domain\SessionId;
use App\Shared\Domain\DomainException;

final class SessionAlreadyStarted extends DomainException
{
    public static function withId(SessionId $id): self
    {
        return new self(\sprintf('Session <%s> has already started.', $id->value));
    }

    public function errorCode(): string
    {
        return 'session-already-started';
    }
}
