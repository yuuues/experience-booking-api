<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Capacity;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\BookingDoesNotBelongToSession;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionAlreadyStarted;
use App\Session\Domain\Exception\SessionInThePast;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Money;
use App\Tests\Doubles\Shared\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-01T10:00:00+00:00');
    }

    #[Test]
    public function it_schedules_a_future_session_and_derives_the_day(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T23:30:00+00:00', capacity: 10);

        self::assertSame(10, $session->availableSeats());
        self::assertSame(0, $session->bookedSeats());
        self::assertSame('2026-10-06', $session->day()->value); // Europe/Madrid is UTC+2 in October
        self::assertInstanceOf(SessionScheduled::class, $session->pullDomainEvents()[0]);
    }

    #[Test]
    public function it_rejects_sessions_in_the_past_or_now(): void
    {
        $this->expectException(SessionInThePast::class);

        self::aSession($this->clock, startsAt: '2026-10-01T10:00:00+00:00');
    }

    #[Test]
    public function it_books_seats_and_computes_total(): void
    {
        $session = self::aSession($this->clock, capacity: 10, priceAmount: 1500);

        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(3), $this->clock);

        self::assertSame(7, $session->availableSeats());
        self::assertSame(3, $session->bookedSeats());
        self::assertSame(4500, $booking->totalPrice()->amount);
        self::assertSame('EUR', $booking->totalPrice()->currency);
        self::assertTrue($booking->sessionId()->equals($session->id()));
        self::assertEquals($this->clock->now(), $booking->bookedAt());
    }

    #[Test]
    public function it_refuses_to_overbook(): void
    {
        $session = self::aSession($this->clock, capacity: 3);
        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);

        $this->expectException(NotEnoughSeatsAvailable::class);

        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000002'), UserId::generate(), Seats::fromInt(2), $this->clock);
    }

    #[Test]
    public function it_allows_booking_exactly_the_remaining_seats(): void
    {
        $session = self::aSession($this->clock, capacity: 3);

        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(3), $this->clock);

        self::assertSame(0, $session->availableSeats());
        self::assertSame(3, $session->bookedSeats());
    }

    #[Test]
    public function overbooking_leaves_seat_counts_unchanged(): void
    {
        $session = self::aSession($this->clock, capacity: 3);
        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);

        try {
            $session->book(BookingId::generate(), BookingReference::fromString('BK-00000002'), UserId::generate(), Seats::fromInt(2), $this->clock);
            self::fail('Expected NotEnoughSeatsAvailable to be thrown.');
        } catch (NotEnoughSeatsAvailable) {
            // expected
        }

        self::assertSame(2, $session->bookedSeats());
        self::assertSame(1, $session->availableSeats());
    }

    #[Test]
    public function it_refuses_booking_once_started(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $this->clock->travelTo('2026-10-05T10:00:00+00:00');

        $this->expectException(SessionAlreadyStarted::class);

        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
    }

    #[Test]
    public function cancelling_releases_seats(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00', capacity: 5);
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->clock->travelTo('2026-10-04T09:59:00+00:00'); // 24h + 1 min before

        $session->cancelBooking($booking, $this->clock);

        self::assertTrue($booking->isCancelled());
        self::assertSame(5, $session->availableSeats());
    }

    #[Test]
    public function it_refuses_cancellation_within_24_hours(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->clock->travelTo('2026-10-04T10:00:01+00:00');

        $this->expectException(CancellationWindowClosed::class);

        $session->cancelBooking($booking, $this->clock);
    }

    #[Test]
    public function cancellation_within_the_window_leaves_the_booking_confirmed_and_seats_consumed(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00', capacity: 5);
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->clock->travelTo('2026-10-04T10:00:01+00:00');

        try {
            $session->cancelBooking($booking, $this->clock);
            self::fail('Expected CancellationWindowClosed to be thrown.');
        } catch (CancellationWindowClosed) {
            // expected
        }

        self::assertFalse($booking->isCancelled());
        self::assertSame(3, $session->availableSeats());
        self::assertSame(2, $session->bookedSeats());
    }

    #[Test]
    public function it_refuses_double_cancellation_before_checking_the_window(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $session->cancelBooking($booking, $this->clock);
        $this->clock->travelTo('2026-10-05T09:00:00+00:00');

        $this->expectException(BookingAlreadyCancelled::class);

        $session->cancelBooking($booking, $this->clock);
    }

    #[Test]
    public function it_refuses_bookings_of_other_sessions(): void
    {
        $session = self::aSession($this->clock);
        $other = self::aSession($this->clock, startsAt: '2026-10-07T10:00:00+00:00');
        $booking = $other->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);

        $this->expectException(BookingDoesNotBelongToSession::class);

        $session->cancelBooking($booking, $this->clock);
    }

    public static function aSession(FixedClock $clock, string $startsAt = '2026-10-05T10:00:00+00:00', int $capacity = 10, int $priceAmount = 1000): Session
    {
        return Session::schedule(
            SessionId::generate(),
            ExperienceId::generate(),
            StartsAt::fromString($startsAt),
            Capacity::fromInt($capacity),
            Money::fromPrimitives($priceAmount, 'EUR'),
            $clock,
        );
    }
}
