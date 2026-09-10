<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Session\Domain\SessionId;
use App\Shared\Domain\NotFoundException;

final class SessionNotFound extends NotFoundException
{
    public static function withId(SessionId $id): self
    {
        return new self(\sprintf('Session <%s> not found.', $id->value));
    }

    public function errorCode(): string
    {
        return 'session-not-found';
    }
}
