<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Notify\SendBookingConfirmationEmailOnBookingConfirmed;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Infrastructure\Contact\FakeUserContactProvider;
use App\Tests\Doubles\Booking\InMemoryMailer;
use App\Tests\Doubles\Booking\InMemorySentNotificationRegistry;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Unit\Session\Domain\SessionTest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendBookingConfirmationEmailOnBookingConfirmedTest extends TestCase
{
    private InMemoryMailer $mailer;
    private SendBookingConfirmationEmailOnBookingConfirmed $handler;
    private BookingConfirmed $event;

    protected function setUp(): void
    {
        $sessions = new InMemorySessionRepository();
        $session = SessionTest::aSession(new FixedClock(), startsAt: '2026-10-05T10:00:00+00:00');
        $sessions->save($session);

        $this->mailer = new InMemoryMailer();
        $this->handler = new SendBookingConfirmationEmailOnBookingConfirmed(
            new FakeUserContactProvider(),
            $sessions,
            $this->mailer,
            new InMemorySentNotificationRegistry(),
        );
        $this->event = new BookingConfirmed(
            reference: 'BK-7F3A2C9K',
            bookingId: '0192b3a4-1234-7abc-8def-0123456789b1',
            sessionId: $session->id()->value,
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: 2,
            totalAmount: 3000,
            totalCurrency: 'EUR',
            occurredOn: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }

    #[Test]
    public function it_sends_a_confirmation_email_to_the_user_contact(): void
    {
        ($this->handler)($this->event);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('user-0192b3a4-1234-7abc-8def-0123456789ad@example.test', $sent[0]->to);
        self::assertSame(BookingEmail::TYPE_CONFIRMATION, $sent[0]->type);
        self::assertSame('BK-7F3A2C9K', $sent[0]->reference);
        self::assertSame(2, $sent[0]->seats);
        self::assertSame(3000, $sent[0]->totalAmount);
        self::assertSame('2026-10-05T10:00:00+00:00', $sent[0]->sessionStartsAt);
    }

    #[Test]
    public function it_is_idempotent_on_redelivery(): void
    {
        ($this->handler)($this->event);
        ($this->handler)($this->event);

        self::assertCount(1, $this->mailer->sent());
    }
}
