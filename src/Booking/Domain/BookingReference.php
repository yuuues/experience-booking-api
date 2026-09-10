<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\StringValueObject;

/** Human-friendly public identifier: BK- + 8 Crockford base32 chars (no I, L, O, U). */
final readonly class BookingReference implements StringValueObject
{
    public const string PREFIX = 'BK-';
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const int LENGTH = 8;
    private const string PATTERN = '/^BK-[0-9A-HJKMNP-TV-Z]{8}$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid booking reference.', $value));
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
