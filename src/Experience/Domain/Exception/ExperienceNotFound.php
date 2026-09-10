<?php

declare(strict_types=1);

namespace App\Experience\Domain\Exception;

use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\NotFoundException;

final class ExperienceNotFound extends NotFoundException
{
    public static function withId(ExperienceId $id): self
    {
        return new self(\sprintf('Experience <%s> not found.', $id->value));
    }

    public function errorCode(): string
    {
        return 'experience-not-found';
    }
}
