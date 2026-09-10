<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\ExperienceId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class ExperienceIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return ExperienceId::class;
    }
}
