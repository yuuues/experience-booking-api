<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Stringable;

/** A value object backed by a single string; lets infrastructure map it generically. */
interface StringValueObject extends Stringable
{
    public static function fromString(string $value): static;
}
