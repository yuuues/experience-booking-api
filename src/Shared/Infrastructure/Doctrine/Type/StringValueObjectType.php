<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a `final readonly` value object exposing `public string $value` and `static fromString(string)`.
 *
 * @template T of object
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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?object
    {
        if (null === $value) {
            return null;
        }

        /** @var T $vo */
        $vo = static::valueObjectClass()::fromString((string) $value); // @phpstan-ignore staticMethod.notFound, cast.string (T is unconstrained by design; DBAL hydrates a string column as string)

        return $vo;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            \is_object($value) && property_exists($value, 'value') => (string) $value->value, // @phpstan-ignore cast.string (guarded by property_exists; value objects expose a scalar $value)
            default => (string) $value, // @phpstan-ignore cast.string (DBAL hydrates a string column as string)
        };
    }
}
