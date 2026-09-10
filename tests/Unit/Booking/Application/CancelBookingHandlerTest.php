<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Application\Cancel\CancelBookingCommand;
use App\Booking\Application\Cancel\CancelBookingHandler;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Booking\SequentialBookingReferenceGenerator;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Doubles\Shared\InMemoryTransactionalRunner;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CancelBookingHandlerTest extends TestCase
{
    private FixedClock $clock;
    private InMemorySessionRepository $sessions;
    private InMemoryDomainEventPublisher $events;
    private CancelBookingHandler $handler;
    private string $sessionId;
    private string $reference;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-01T10:00:00+00:00');
        $this->sessions = new InMemorySessionRepository();
        $bookings = new InMemoryBookingRepository($this->sessions);
        $transaction = new InMemoryTransactionalRunner();
        $this->events = new InMemoryDomainEventPublisher();

        $session = SessionTest::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00', capacity: 5);
        $this->sessions->save($session);
        $this->sessionId = $session->id()->value;

        $book = new BookSeatsHandler($this->sessions, $bookings, new SequentialBookingReferenceGenerator(), $this->clock, $transaction, $this->events);
        $this->reference = $book(new BookSeatsCommand(BookingId::generate()->value, $this->sessionId, '0192b3a4-1234-7abc-8def-0123456789ad', 2))->reference;

        $this->handler = new CancelBookingHandler($bookings, $this->sessions, $this->clock, $transaction, $this->events);
    }

    #[Test]
    public function it_cancels_and_releases_seats(): void
    {
        $response = ($this->handler)(new CancelBookingCommand($this->reference));

        self::assertSame('cancelled', $response->status);
        self::assertNotNull($response->cancelledAt);
        self::assertSame(5, $this->sessions->find(\App\Session\Domain\SessionId::fromString($this->sessionId))?->availableSeats());
        self::assertSame(2, $this->sessions->lockedReads); // one from booking, one from cancelling
        self::assertCount(1, $this->events->publishedOf(BookingCancelled::class));
    }

    #[Test]
    public function it_refuses_double_cancellation(): void
    {
        ($this->handler)(new CancelBookingCommand($this->reference));

        $this->expectException(BookingAlreadyCancelled::class);

        ($this->handler)(new CancelBookingCommand($this->reference));
    }

    #[Test]
    public function it_refuses_inside_24h_window(): void
    {
        $this->clock->travelTo('2026-10-04T12:00:00+00:00');

        $this->expectException(CancellationWindowClosed::class);

        ($this->handler)(new CancelBookingCommand($this->reference));
    }

    #[Test]
    public function it_fails_for_unknown_reference(): void
    {
        $this->expectException(BookingNotFound::class);

        ($this->handler)(new CancelBookingCommand('BK-ZZZZZZZZ'));
    }
}
