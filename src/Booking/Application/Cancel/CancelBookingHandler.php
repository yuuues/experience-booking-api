<?php

declare(strict_types=1);

namespace App\Booking\Application\Cancel;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionRepository;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Application\TransactionalRunner;
use App\Shared\Domain\Clock;

final readonly class CancelBookingHandler
{
    public function __construct(
        private BookingRepository $bookings,
        private SessionRepository $sessions,
        private Clock $clock,
        private TransactionalRunner $transaction,
        private DomainEventPublisher $events,
    ) {}

    public function __invoke(CancelBookingCommand $command): BookingResponse
    {
        $reference = BookingReference::fromString($command->reference);

        return $this->transaction->run(function () use ($reference): BookingResponse {
            $booking = $this->bookings->findByReference($reference) ?? throw BookingNotFound::withReference($reference);
            $session = $this->sessions->findForUpdate($booking->sessionId()) ?? throw SessionNotFound::withId($booking->sessionId());

            $session->cancelBooking($booking, $this->clock);

            $this->sessions->save($session);
            $this->bookings->save($booking);
            $this->events->publish(...$session->pullDomainEvents(), ...$booking->pullDomainEvents());

            return BookingResponse::fromBooking($booking);
        });
    }
}
