<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\ProviderId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class ProviderIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return ProviderId::class;
    }
}
