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
    public function itMultipliesKeepingCurrency(): void
    {
        $price = Money::fromPrimitives(1550, 'EUR');

        $total = $price->multiply(3);

        self::assertSame(4650, $total->amount);
        self::assertSame('EUR', $total->currency);
    }

    #[Test]
    public function itRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(-1, 'EUR');
    }

    #[Test]
    public function itRejectsMalformedCurrency(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(100, 'eur');
    }

    #[Test]
    public function itComparesByValue(): void
    {
        self::assertTrue(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'EUR')));
        self::assertFalse(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'USD')));
    }
}
