<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\Title;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;

/** @extends StringValueObjectType<Title> */
final class TitleType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Title::class;
    }
}
