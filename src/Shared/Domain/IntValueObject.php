<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** A value object backed by a single integer; lets infrastructure map it generically. */
interface IntValueObject
{
    public static function fromInt(int $value): static;

    public function toInt(): int;
}
