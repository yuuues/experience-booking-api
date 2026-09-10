<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\IntValueObject;
use App\Shared\Domain\InvalidValue;

final readonly class Capacity implements IntValueObject
{
    /**
     * Upper bound for a single session: far above any real capacity, and comfortably inside the
     * INT4 column the session row uses, so an absurd request fails as a domain rule instead of
     * as a database overflow.
     */
    public const int MAX = 100_000;

    private function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValue('Capacity must be at least 1.');
        }
        if ($value > self::MAX) {
            throw new InvalidValue(\sprintf('Capacity cannot exceed %d.', self::MAX));
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
