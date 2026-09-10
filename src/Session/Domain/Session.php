<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\BookingDoesNotBelongToSession;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionAlreadyStarted;
use App\Session\Domain\Exception\SessionInThePast;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;

/**
 * Guards the seat count. Bookings are created and cancelled through the session so that
 * capacity and the time-window rules live next to the data they depend on.
 */
final class Session extends AggregateRoot
{
    public const int CANCELLATION_WINDOW_HOURS = 24;

    private int $bookedSeats = 0;

    private function __construct(
        private readonly SessionId $id,
        private readonly ExperienceId $experienceId,
        private readonly StartsAt $startsAt,
        private readonly SessionDay $day,
        private readonly Capacity $capacity,
        private readonly Money $price,
    ) {}

    public static function schedule(
        SessionId $id,
        ExperienceId $experienceId,
        StartsAt $startsAt,
        Capacity $capacity,
        Money $price,
        Clock $clock,
    ): self {
        if ($startsAt->isAtOrBefore($clock->now())) {
            throw SessionInThePast::at($startsAt);
        }

        // The day is snapshotted here, in the platform time zone, on purpose: the (experience_id, day)
        // unique index relies on this value staying stable once the session exists.
        $session = new self($id, $experienceId, $startsAt, $startsAt->dayIn($clock->timeZone()), $capacity, $price);
        $session->record(new SessionScheduled($id->value, $experienceId->value, $startsAt->toAtom(), $capacity->value, $clock->now()));

        return $session;
    }

    public function book(BookingId $bookingId, BookingReference $reference, UserId $userId, Seats $seats, Clock $clock): Booking
    {
        $now = $clock->now();
        if ($this->startsAt->isAtOrBefore($now)) {
            throw SessionAlreadyStarted::withId($this->id);
        }
        if ($seats->value > $this->availableSeats()) {
            throw NotEnoughSeatsAvailable::for($this->id, $seats->value, $this->availableSeats());
        }

        $booking = Booking::confirm($bookingId, $reference, $this->id, $userId, $seats, $this->price->multiply($seats->value), $now);
        $this->bookedSeats += $seats->value;

        return $booking;
    }

    /**
     * @throws BookingAlreadyCancelled
     * @throws CancellationWindowClosed
     * @throws BookingDoesNotBelongToSession
     */
    public function cancelBooking(Booking $booking, Clock $clock): void
    {
        if (!$booking->sessionId()->equals($this->id)) {
            throw BookingDoesNotBelongToSession::for($booking->reference(), $this->id);
        }
        if ($booking->isCancelled()) {
            throw BookingAlreadyCancelled::withReference($booking->reference());
        }
        $now = $clock->now();
        if ($this->startsAt->isWithinHoursBefore($now, self::CANCELLATION_WINDOW_HOURS)) {
            throw CancellationWindowClosed::for($booking->reference(), $this->startsAt);
        }

        $booking->cancel($now);
        $this->bookedSeats -= $booking->seats()->value;
    }

    public function availableSeats(): int
    {
        return $this->capacity->value - $this->bookedSeats;
    }

    public function id(): SessionId
    {
        return $this->id;
    }

    public function experienceId(): ExperienceId
    {
        return $this->experienceId;
    }

    public function startsAt(): StartsAt
    {
        return $this->startsAt;
    }

    public function day(): SessionDay
    {
        return $this->day;
    }

    public function capacity(): Capacity
    {
        return $this->capacity;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function bookedSeats(): int
    {
        return $this->bookedSeats;
    }
}
