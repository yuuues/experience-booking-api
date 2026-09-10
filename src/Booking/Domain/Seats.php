<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Shared\Domain\IntValueObject;
use App\Shared\Domain\InvalidValue;

final readonly class Seats implements IntValueObject
{
    private function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValue('A booking needs at least one seat.');
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
