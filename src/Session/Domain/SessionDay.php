<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;

/** Calendar day (Y-m-d) in the platform time zone; used for the one-session-per-day rule. */
final readonly class SessionDay
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === strtotime($value)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid day.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
