<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Find\FindBookingHandler;
use App\Booking\Application\Find\FindBookingQuery;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Unit\Booking\Domain\BookingTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindBookingHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_the_booking(): void
    {
        $bookings = new InMemoryBookingRepository();
        $booking = BookingTest::aBooking();
        $bookings->save($booking);

        $response = (new FindBookingHandler($bookings))(new FindBookingQuery('BK-00000001'));

        self::assertSame('BK-00000001', $response->reference);
        self::assertSame(2, $response->seats);
        self::assertSame('2026-10-01T10:00:00+00:00', $response->bookedAt);
        self::assertNull($response->cancelledAt);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(BookingNotFound::class);

        (new FindBookingHandler(new InMemoryBookingRepository()))(new FindBookingQuery('BK-00000009'));
    }
}
