<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Exception\BookingReferenceExhausted;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionNotFound;
use App\Tests\Doubles\Booking\AlwaysSameBookingReferenceGenerator;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Booking\SequentialBookingReferenceGenerator;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Doubles\Shared\InMemoryTransactionalRunner;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookSeatsHandlerTest extends TestCase
{
    private InMemorySessionRepository $sessions;
    private InMemoryBookingRepository $bookings;
    private InMemoryTransactionalRunner $transaction;
    private InMemoryDomainEventPublisher $events;
    private BookSeatsHandler $handler;
    private string $sessionId;

    protected function setUp(): void
    {
        $clock = new FixedClock('2026-10-01T10:00:00+00:00');
        $this->transaction = new InMemoryTransactionalRunner();
        $this->sessions = new InMemorySessionRepository($this->transaction);
        $this->bookings = new InMemoryBookingRepository($this->sessions, $this->transaction);
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new BookSeatsHandler(
            $this->sessions,
            $this->bookings,
            new SequentialBookingReferenceGenerator(),
            $clock,
            $this->transaction,
            $this->events,
        );

        $session = SessionTest::aSession($clock, capacity: 5, priceAmount: 2000);
        $this->sessions->save($session);
        $this->sessionId = $session->id()->value;

        // The seed save above happens outside any transaction; reset the tracking so each test's
        // assertions are only about the save() calls made by the handler invocation under test.
        $this->sessions->resetSaveTracking();
        $this->bookings->resetSaveTracking();
    }

    #[Test]
    public function it_books_inside_a_transaction_with_a_locked_session(): void
    {
        $response = ($this->handler)($this->command(seats: 2));

        self::assertSame('BK-00000001', $response->reference);
        self::assertSame('confirmed', $response->status);
        self::assertSame(4000, $response->total->amount);
        self::assertSame(1, $this->transaction->transactions);
        self::assertSame(1, $this->sessions->lockedReads);
        self::assertNotNull($this->bookings->findByReference(BookingReference::fromString('BK-00000001')));
        self::assertSame(3, $this->sessions->find($this->session())?->availableSeats());
        self::assertCount(1, $this->events->publishedOf(BookingConfirmed::class));
        // Nothing in the lock-count assertions above would catch a save() hoisted out of the
        // transaction; these pin that both saves happened while it was open.
        self::assertSame([true], $this->sessions->saveTransactionStates);
        self::assertSame([true], $this->bookings->saveTransactionStates);
    }

    #[Test]
    public function it_skips_references_already_taken(): void
    {
        // BookingTest::aBooking() carries reference BK-00000001, the first one the sequential generator yields.
        $this->bookings->save(\App\Tests\Unit\Booking\Domain\BookingTest::aBooking());

        $response = ($this->handler)($this->command(seats: 1));

        self::assertSame('BK-00000002', $response->reference);
    }

    #[Test]
    public function it_exhausts_reference_attempts_and_changes_nothing(): void
    {
        $taken = BookingReference::fromString('BK-00000001');
        $this->bookings->save(\App\Tests\Unit\Booking\Domain\BookingTest::aBooking());
        $this->bookings->resetSaveTracking();

        $generator = new AlwaysSameBookingReferenceGenerator($taken);
        $handler = new BookSeatsHandler(
            $this->sessions,
            $this->bookings,
            $generator,
            new FixedClock('2026-10-01T10:00:00+00:00'),
            $this->transaction,
            $this->events,
        );

        try {
            $handler($this->command(seats: 1));
            self::fail('Expected BookingReferenceExhausted to be thrown.');
        } catch (BookingReferenceExhausted) {
            // Pins the bound itself, not just the exception: the generator is asked exactly
            // MAX_REFERENCE_ATTEMPTS times, never more, never fewer.
            self::assertSame(5, $generator->calls);
            // No new booking was saved: only the one seeded directly above remains.
            self::assertCount(1, $this->bookings->all());
            self::assertSame([], $this->bookings->saveTransactionStates);
            // The session was never touched: no lock taken, no seats consumed.
            self::assertSame(0, $this->sessions->lockedReads);
            self::assertSame(5, $this->sessions->find($this->session())?->availableSeats());
        }
    }

    #[Test]
    public function it_fails_when_not_enough_seats(): void
    {
        try {
            ($this->handler)($this->command(seats: 6));
            self::fail('Expected NotEnoughSeatsAvailable to be thrown.');
        } catch (NotEnoughSeatsAvailable) {
            self::assertSame(5, $this->sessions->find($this->session())?->availableSeats());
        }
    }

    #[Test]
    public function it_fails_when_session_missing(): void
    {
        $this->expectException(SessionNotFound::class);

        ($this->handler)(new BookSeatsCommand('0192b3a4-1234-7abc-8def-0123456789b1', '0192b3a4-1234-7abc-8def-0123456789ff', '0192b3a4-1234-7abc-8def-0123456789ad', 1));
    }

    private function command(int $seats): BookSeatsCommand
    {
        return new BookSeatsCommand(
            bookingId: \App\Booking\Domain\BookingId::generate()->value,
            sessionId: $this->sessionId,
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: $seats,
        );
    }

    private function session(): \App\Session\Domain\SessionId
    {
        return \App\Session\Domain\SessionId::fromString($this->sessionId);
    }
}
