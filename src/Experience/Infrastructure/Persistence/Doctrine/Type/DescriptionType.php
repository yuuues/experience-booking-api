<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\Description;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/** @extends StringValueObjectType<Description> */
final class DescriptionType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Description::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }
}
