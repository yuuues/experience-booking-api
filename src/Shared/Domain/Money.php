<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class Money
{
    /**
     * Upper bound for a single amount in minor units: one million currency units. Well beyond any
     * per-seat price, and low enough that a product of amounts and seat counts stays far from the
     * integer range PHP and the database share.
     */
    public const int MAX_AMOUNT = 100_000_000;

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
        if ($amount > self::MAX_AMOUNT) {
            throw new InvalidValue(\sprintf('Money amount cannot exceed %d minor units.', self::MAX_AMOUNT));
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
        // Without this guard the product silently becomes a float and the constructor fails with a
        // TypeError under strict_types. Callers bound both operands (amount <= MAX_AMOUNT, factor
        // <= Capacity::MAX), so this is unreachable in practice — the guard belongs in the code
        // rather than in that reasoning.
        if ($factor > 0 && $this->amount > intdiv(\PHP_INT_MAX, $factor)) {
            throw new InvalidValue(\sprintf('Multiplying %d by %d would overflow.', $this->amount, $factor));
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
