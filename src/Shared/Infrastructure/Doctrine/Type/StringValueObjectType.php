<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\StringValueObject;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a value object backed by a single string. Subclasses name the VO class.
 *
 * @template T of StringValueObject
 */
abstract class StringValueObjectType extends Type
{
    /** @return class-string<T> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    /** @return T|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringValueObject
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw ValueNotConvertible::new($value, static::valueObjectClass());
        }

        return static::valueObjectClass()::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof StringValueObject => (string) $value,
            default => throw InvalidType::new($value, static::valueObjectClass(), ['null', StringValueObject::class]),
        };
    }
}
