<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Shared\Domain\InvalidValue;

final readonly class Email
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid email address.', $value));
        }

        return new self(strtolower($value));
    }
}
