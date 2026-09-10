<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingReferenceTest extends TestCase
{
    #[Test]
    public function it_accepts_crockford_base32_and_uppercases(): void
    {
        self::assertSame('BK-7F3A2C9K', BookingReference::fromString('bk-7f3a2c9k')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'wrong prefix' => ['XX-7F3A2C9K'];
        yield 'too short' => ['BK-7F3A2C'];
        yield 'ambiguous letter I' => ['BK-7F3A2CIK'];
        yield 'ambiguous letter O' => ['BK-7F3A2COK'];
        yield 'letter U' => ['BK-7F3A2CUK'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_rejects_invalid(string $value): void
    {
        $this->expectException(InvalidValue::class);

        BookingReference::fromString($value);
    }
}
