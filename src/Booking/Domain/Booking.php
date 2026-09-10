<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Session\Domain\SessionId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Money;
use DateTimeImmutable;

final class Booking extends AggregateRoot
{
    private BookingStatus $status;
    private ?DateTimeImmutable $cancelledAt = null;

    private function __construct(
        private readonly BookingId $id,
        private readonly BookingReference $reference,
        private readonly SessionId $sessionId,
        private readonly UserId $userId,
        private readonly Seats $seats,
        private readonly Money $totalPrice,
        private readonly DateTimeImmutable $bookedAt,
    ) {
        $this->status = BookingStatus::Confirmed;
    }

    /**
     * @internal only Session::book() may create bookings: the session owns the seat count and the price
     */
    public static function confirm(
        BookingId $id,
        BookingReference $reference,
        SessionId $sessionId,
        UserId $userId,
        Seats $seats,
        Money $totalPrice,
        DateTimeImmutable $bookedAt,
    ): self {
        $booking = new self($id, $reference, $sessionId, $userId, $seats, $totalPrice, $bookedAt);
        $booking->record(new BookingConfirmed(
            $reference->value,
            $id->value,
            $sessionId->value,
            $userId->value,
            $seats->value,
            $totalPrice->amount,
            $totalPrice->currency,
            $bookedAt,
        ));

        return $booking;
    }

    /** @internal Only Session::cancelBooking() may cancel: the session enforces the time window and releases seats. */
    public function cancel(DateTimeImmutable $at): void
    {
        if ($this->isCancelled()) {
            throw BookingAlreadyCancelled::withReference($this->reference);
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelledAt = $at;
        $this->record(new BookingCancelled(
            $this->reference->value,
            $this->id->value,
            $this->sessionId->value,
            $this->userId->value,
            $this->seats->value,
            $at,
        ));
    }

    public function isCancelled(): bool
    {
        return BookingStatus::Cancelled === $this->status;
    }

    public function id(): BookingId
    {
        return $this->id;
    }

    public function reference(): BookingReference
    {
        return $this->reference;
    }

    public function sessionId(): SessionId
    {
        return $this->sessionId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function seats(): Seats
    {
        return $this->seats;
    }

    public function totalPrice(): Money
    {
        return $this->totalPrice;
    }

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function bookedAt(): DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }
}
