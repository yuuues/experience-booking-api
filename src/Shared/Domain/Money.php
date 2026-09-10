<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class Money
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    /** @param int $amount minor units (cents) */
    public static function fromPrimitives(int $amount, string $currency): self
    {
        if ($amount < 0) {
            throw new InvalidValue('Money amount cannot be negative.');
        }
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidValue(\sprintf('<%s> is not a valid ISO 4217 currency code.', $currency));
        }

        return new self($amount, $currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidValue('Money factor cannot be negative.');
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
