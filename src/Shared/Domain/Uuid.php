<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Stringable;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

abstract class Uuid implements Stringable
{
    final private function __construct(public readonly string $value) {}

    public static function generate(): static
    {
        return new static(SymfonyUuid::v7()->toRfc4122());
    }

    public static function fromString(string $value): static
    {
        if (!SymfonyUuid::isValid($value)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid UUID.', $value));
        }

        return new static(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
