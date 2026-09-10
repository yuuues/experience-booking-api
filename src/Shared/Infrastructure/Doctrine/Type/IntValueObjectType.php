<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\IntValueObject;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a value object backed by a single integer. Subclasses name the VO class.
 *
 * @template T of IntValueObject
 */
abstract class IntValueObjectType extends Type
{
    /** @return class-string<T> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    /** @return T|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?IntValueObject
    {
        if (null === $value) {
            return null;
        }

        // Drivers that stringify integer columns are still exact; anything else is a mapping bug.
        if (\is_string($value) && (string) (int) $value === $value) {
            $value = (int) $value;
        }

        if (!\is_int($value)) {
            throw ValueNotConvertible::new($value, static::valueObjectClass());
        }

        return static::valueObjectClass()::fromInt($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        return match (true) {
            null === $value => null,
            $value instanceof IntValueObject => $value->toInt(),
            default => throw InvalidType::new($value, static::valueObjectClass(), ['null', IntValueObject::class]),
        };
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }
}
