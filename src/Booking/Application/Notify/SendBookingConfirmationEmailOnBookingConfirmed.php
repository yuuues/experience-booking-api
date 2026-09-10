<?php

declare(strict_types=1);

namespace App\Booking\Application\Notify;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;
use App\Booking\Domain\Notification\SentNotificationRegistry;
use App\Booking\Domain\Notification\UserContactProvider;
use App\Booking\Domain\UserId;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendBookingConfirmationEmailOnBookingConfirmed
{
    public function __construct(
        private UserContactProvider $contacts,
        private SessionRepository $sessions,
        private MailerPort $mailer,
        private SentNotificationRegistry $sent,
    ) {}

    public function __invoke(BookingConfirmed $event): void
    {
        $reference = BookingReference::fromString($event->reference);
        if ($this->sent->wasSent($reference, BookingEmail::TYPE_CONFIRMATION)) {
            return;
        }

        $sessionId = SessionId::fromString($event->sessionId);
        $session = $this->sessions->find($sessionId) ?? throw SessionNotFound::withId($sessionId);

        $this->mailer->send(new BookingEmail(
            to: $this->contacts->emailFor(UserId::fromString($event->userId))->value,
            type: BookingEmail::TYPE_CONFIRMATION,
            reference: $reference->value,
            seats: $event->seats,
            totalAmount: $event->totalAmount,
            totalCurrency: $event->totalCurrency,
            sessionStartsAt: $session->startsAt()->toAtom(),
        ));

        $this->sent->markSent($reference, BookingEmail::TYPE_CONFIRMATION);
    }
}
