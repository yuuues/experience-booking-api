<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_multiplies_keeping_currency(): void
    {
        $price = Money::fromPrimitives(1550, 'EUR');

        $total = $price->multiply(3);

        self::assertSame(4650, $total->amount);
        self::assertSame('EUR', $total->currency);
    }

    #[Test]
    public function it_rejects_negative_amount(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(-1, 'EUR');
    }

    #[Test]
    public function it_rejects_malformed_currency(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(100, 'eur');
    }

    #[Test]
    public function it_accepts_the_maximum_amount(): void
    {
        self::assertSame(Money::MAX_AMOUNT, Money::fromPrimitives(Money::MAX_AMOUNT, 'EUR')->amount);
    }

    #[Test]
    public function it_rejects_an_amount_above_the_maximum(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(Money::MAX_AMOUNT + 1, 'EUR');
    }

    #[Test]
    public function it_rejects_a_multiplication_that_would_overflow(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(Money::MAX_AMOUNT, 'EUR')->multiply(\PHP_INT_MAX);
    }

    #[Test]
    public function it_multiplies_by_zero(): void
    {
        self::assertSame(0, Money::fromPrimitives(Money::MAX_AMOUNT, 'EUR')->multiply(0)->amount);
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        self::assertTrue(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'EUR')));
        self::assertFalse(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'USD')));
    }
}
