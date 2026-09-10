<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a `final readonly` value object exposing `public int $value` and `static fromInt(int)`.
 *
 * @template T of object
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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?object
    {
        if (null === $value) {
            return null;
        }

        /** @var T $vo */
        $vo = static::valueObjectClass()::fromInt((int) $value); // @phpstan-ignore staticMethod.notFound, cast.int (T is unconstrained by design; DBAL hydrates an integer column as int|string)

        return $vo;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        return match (true) {
            null === $value => null,
            \is_object($value) && property_exists($value, 'value') => (int) $value->value, // @phpstan-ignore cast.int (guarded by property_exists; value objects expose a scalar $value)
            default => (int) $value, // @phpstan-ignore cast.int (DBAL hydrates an integer column as int|string)
        };
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }
}
