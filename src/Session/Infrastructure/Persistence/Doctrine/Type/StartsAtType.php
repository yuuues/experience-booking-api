<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\StartsAt;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Type;

/**
 * Wraps (not extends) DateTimeTzImmutableType: its convertToPHPValue() return type is
 * ?DateTimeImmutable, so a subclass could not narrow it to ?StartsAt.
 */
final class StartsAtType extends Type
{
    private ?DateTimeTzImmutableType $inner = null;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTzTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StartsAt
    {
        $dateTime = $this->inner()->convertToPHPValue($value, $platform);

        return null === $dateTime ? null : StartsAt::fromDateTime($dateTime);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $this->inner()->convertToDatabaseValue($value instanceof StartsAt ? $value->value : $value, $platform);
    }

    private function inner(): DateTimeTzImmutableType
    {
        return $this->inner ??= new DateTimeTzImmutableType();
    }
}
