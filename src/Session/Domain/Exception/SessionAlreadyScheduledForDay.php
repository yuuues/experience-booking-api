<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Experience\Domain\ExperienceId;
use App\Session\Domain\SessionDay;
use App\Shared\Domain\ConflictException;

final class SessionAlreadyScheduledForDay extends ConflictException
{
    public static function on(ExperienceId $experienceId, SessionDay $day): self
    {
        return new self(\sprintf('Experience <%s> already has a session on %s.', $experienceId->value, $day->value));
    }

    public function errorCode(): string
    {
        return 'session-already-scheduled-for-day';
    }
}
