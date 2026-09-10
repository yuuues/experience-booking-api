<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\StringValueObject;

/** Calendar day (Y-m-d) in the platform time zone; used for the one-session-per-day rule. */
final readonly class SessionDay implements StringValueObject
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid day.', $value));
        }

        [, $year, $month, $day] = $matches;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid day.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
