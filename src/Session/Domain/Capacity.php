<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\IntValueObject;
use App\Shared\Domain\InvalidValue;

final readonly class Capacity implements IntValueObject
{
    private function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValue('Capacity must be at least 1.');
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
