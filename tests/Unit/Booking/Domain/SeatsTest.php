<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\Seats;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeatsTest extends TestCase
{
    #[Test]
    public function it_requires_at_least_one_seat(): void
    {
        self::assertSame(1, Seats::fromInt(1)->value);

        $this->expectException(InvalidValue::class);
        Seats::fromInt(0);
    }
}
