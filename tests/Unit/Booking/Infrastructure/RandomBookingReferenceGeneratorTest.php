<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Infrastructure;

use App\Booking\Infrastructure\Reference\RandomBookingReferenceGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RandomBookingReferenceGeneratorTest extends TestCase
{
    #[Test]
    public function it_generates_valid_distinct_references(): void
    {
        $generator = new RandomBookingReferenceGenerator();
        $seen = [];

        for ($i = 0; $i < 1000; ++$i) {
            $reference = $generator->next()->value;
            self::assertMatchesRegularExpression('/^BK-[0-9A-HJKMNP-TV-Z]{8}$/', $reference);
            $seen[$reference] = true;
        }

        self::assertCount(1000, $seen);
    }
}
