<?php

declare(strict_types=1);

namespace App\Booking\Application\Book;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;
use App\Booking\Domain\BookingRepository;
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

        return $this->transaction->run(function () use ($sessionId, $bookingId, $userId, $seats): BookingResponse {
            // Row lock on the session: concurrent bookings for the same session queue here.
            $session = $this->sessions->findForUpdate($sessionId) ?? throw SessionNotFound::withId($sessionId);

            $booking = $session->book($bookingId, $this->uniqueReference(), $userId, $seats, $this->clock);

            $this->sessions->save($session);
            $this->bookings->save($booking);
            $this->events->publish(...$session->pullDomainEvents(), ...$booking->pullDomainEvents());

            return BookingResponse::fromBooking($booking);
        });
    }

    private function uniqueReference(): BookingReference
    {
        do {
            $reference = $this->references->next();
        } while ($this->bookings->existsByReference($reference));

        return $reference;
    }
}
