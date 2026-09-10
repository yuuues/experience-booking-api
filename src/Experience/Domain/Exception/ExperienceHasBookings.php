<?php

declare(strict_types=1);

namespace App\Experience\Domain\Exception;

use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\ConflictException;

final class ExperienceHasBookings extends ConflictException
{
    public static function withId(ExperienceId $id): self
    {
        return new self(\sprintf('Experience <%s> cannot be edited because it already has confirmed bookings.', $id->value));
    }

    public function errorCode(): string
    {
        return 'experience-has-bookings';
    }
}
