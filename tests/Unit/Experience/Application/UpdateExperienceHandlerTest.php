<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Application\Update\UpdateExperienceCommand;
use App\Experience\Application\Update\UpdateExperienceHandler;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\Experience;
use App\Session\Domain\Capacity;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Money;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateExperienceHandlerTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryExperienceRepository $experiences;
    private InMemorySessionRepository $sessions;
    private InMemoryBookingRepository $bookings;
    private InMemoryDomainEventPublisher $events;
    private UpdateExperienceHandler $handler;
    private Experience $experience;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->experiences = new InMemoryExperienceRepository();
        $this->sessions = new InMemorySessionRepository();
        $this->bookings = new InMemoryBookingRepository($this->sessions);
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new UpdateExperienceHandler($this->experiences, $this->bookings, $this->events);

        $this->experience = ExperienceTest::anExperience();
        $this->experiences->save($this->experience);
    }

    #[Test]
    public function it_updates_when_no_confirmed_bookings(): void
    {
        $response = ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Kayak at sunset', 'Evening paddle'));

        self::assertSame('Kayak at sunset', $response->title);
        self::assertSame('Evening paddle', $this->experiences->find($this->experience->id())?->description()->value);
        self::assertCount(1, $this->events->publishedOf(ExperienceUpdated::class));
    }

    #[Test]
    public function it_still_updates_when_only_cancelled_bookings_exist(): void
    {
        $session = $this->aSession();
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $session->cancelBooking($booking, $this->clock);
        $this->sessions->save($session);
        $this->bookings->save($booking);

        $response = ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Renamed', 'Desc'));

        self::assertSame('Renamed', $response->title);
    }

    #[Test]
    public function it_refuses_when_a_confirmed_booking_exists(): void
    {
        $session = $this->aSession();
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->sessions->save($session);
        $this->bookings->save($booking);

        $this->expectException(ExperienceHasBookings::class);

        ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Renamed', 'Desc'));
    }

    #[Test]
    public function it_fails_for_unknown_experience(): void
    {
        $this->expectException(ExperienceNotFound::class);

        ($this->handler)(new UpdateExperienceCommand('0192b3a4-1234-7abc-8def-0123456789ff', 'x', 'y'));
    }

    private function aSession(): Session
    {
        return Session::schedule(SessionId::generate(), $this->experience->id(), StartsAt::fromString('2026-10-05T10:00:00+00:00'), Capacity::fromInt(5), Money::fromPrimitives(1000, 'EUR'), $this->clock);
    }
}
