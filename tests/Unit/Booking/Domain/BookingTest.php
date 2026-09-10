<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingStatus;
use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Session\Domain\SessionId;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    #[Test]
    public function it_is_confirmed_on_creation_and_records_event(): void
    {
        $booking = self::aBooking();

        self::assertSame(BookingStatus::Confirmed, $booking->status());
        self::assertFalse($booking->isCancelled());
        self::assertNull($booking->cancelledAt());
        self::assertSame(3000, $booking->totalPrice()->amount);

        $events = $booking->pullDomainEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(BookingConfirmed::class, $events[0]);
        self::assertSame('BK-00000001', $events[0]->reference);
        self::assertSame(2, $events[0]->seats);
        self::assertSame(3000, $events[0]->totalAmount);
    }

    #[Test]
    public function it_cancels_once(): void
    {
        $booking = self::aBooking();
        $booking->pullDomainEvents();
        $at = new DateTimeImmutable('2026-10-02T09:00:00+00:00');

        $booking->cancel($at);

        self::assertTrue($booking->isCancelled());
        self::assertEquals($at, $booking->cancelledAt());
        self::assertInstanceOf(BookingCancelled::class, $booking->pullDomainEvents()[0]);

        $this->expectException(BookingAlreadyCancelled::class);
        $booking->cancel($at);
    }

    public static function aBooking(): Booking
    {
        return Booking::confirm(
            BookingId::generate(),
            BookingReference::fromString('BK-00000001'),
            SessionId::generate(),
            UserId::generate(),
            Seats::fromInt(2),
            Money::fromPrimitives(3000, 'EUR'),
            new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }
}
