<?php

declare(strict_types=1);

namespace App\Booking\Application\Book;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Exception\BookingReferenceExhausted;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Application\TransactionalRunner;
use App\Shared\Domain\Clock;

final readonly class BookSeatsHandler
{
    /** Bounded so a degenerate generator cannot spin forever while holding the session row lock. */
    private const int MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private SessionRepository $sessions,
        private BookingRepository $bookings,
        private BookingReferenceGenerator $references,
        private Clock $clock,
        private TransactionalRunner $transaction,
        private DomainEventPublisher $events,
    ) {}

    public function __invoke(BookSeatsCommand $command): BookingResponse
    {
        $sessionId = SessionId::fromString($command->sessionId);
        $bookingId = BookingId::fromString($command->bookingId);
        $userId = UserId::fromString($command->userId);
        $seats = Seats::fromInt($command->seats);
        // Generated before the transaction opens: the uniqueness check is a plain read that does
        // not need to run while the session row lock is held. The DB unique index (Task 14) is the
        // real guard against a lost race between here and the insert.
        $reference = $this->uniqueReference();

        return $this->transaction->run(function () use ($sessionId, $bookingId, $userId, $seats, $reference): BookingResponse {
            // Row lock on the session: concurrent bookings for the same session queue here.
            $session = $this->sessions->findForUpdate($sessionId) ?? throw SessionNotFound::withId($sessionId);

            $booking = $session->book($bookingId, $reference, $userId, $seats, $this->clock);

            $this->sessions->save($session);
            $this->bookings->save($booking);
            $this->events->publish(...$session->pullDomainEvents(), ...$booking->pullDomainEvents());

            return BookingResponse::fromBooking($booking);
        });
    }

    private function uniqueReference(): BookingReference
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; ++$attempt) {
            $reference = $this->references->next();
            if (!$this->bookings->existsByReference($reference)) {
                return $reference;
            }
        }

        throw BookingReferenceExhausted::afterAttempts(self::MAX_REFERENCE_ATTEMPTS);
    }
}
