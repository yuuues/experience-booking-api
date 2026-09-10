<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Session\Domain\Capacity;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapacityTest extends TestCase
{
    #[Test]
    public function it_accepts_one_seat(): void
    {
        self::assertSame(1, Capacity::fromInt(1)->toInt());
    }

    #[Test]
    public function it_rejects_less_than_one_seat(): void
    {
        $this->expectException(InvalidValue::class);

        Capacity::fromInt(0);
    }

    #[Test]
    public function it_accepts_the_maximum(): void
    {
        self::assertSame(Capacity::MAX, Capacity::fromInt(Capacity::MAX)->toInt());
    }

    #[Test]
    public function it_rejects_more_than_the_maximum(): void
    {
        $this->expectException(InvalidValue::class);

        Capacity::fromInt(Capacity::MAX + 1);
    }
}
