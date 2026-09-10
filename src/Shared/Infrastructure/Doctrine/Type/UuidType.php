<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Uuid;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Maps a Uuid value object to a native UUID column. Subclasses name the VO class. */
abstract class UuidType extends Type
{
    /** @return class-string<Uuid> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if (null === $value) {
            return null;
        }

        return static::valueObjectClass()::fromString((string) $value); // @phpstan-ignore cast.string (DBAL hydrates a UUID column as string)
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof Uuid => $value->value,
            default => (string) $value, // @phpstan-ignore cast.string (DBAL hydrates a UUID column as string)
        };
    }
}
