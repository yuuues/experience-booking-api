<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceRepository;
use App\Session\Domain\Capacity;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSessionRepositoryTest extends KernelTestCase
{
    private SessionRepository $sessions;
    private EntityManagerInterface $entityManager;
    private string $experienceId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sessions = self::getContainer()->get(SessionRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $experience = ExperienceTest::anExperience();
        self::getContainer()->get(ExperienceRepository::class)->save($experience);
        $this->experienceId = $experience->id()->value;
    }

    #[Test]
    public function it_persists_and_rehydrates_value_objects(): void
    {
        $session = $this->aSession('+3 days 10:00');

        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->sessions->find($session->id());
        self::assertNotNull($found);
        self::assertSame($session->startsAt()->toAtom(), $found->startsAt()->toAtom());
        self::assertSame($session->day()->value, $found->day()->value);
        self::assertSame(10, $found->capacity()->value);
        self::assertTrue($found->price()->equals(Money::fromPrimitives(1500, 'EUR')));
        self::assertSame(0, $found->bookedSeats());
    }

    #[Test]
    public function booked_seats_survive_the_persistence_round_trip(): void
    {
        $clock = self::getContainer()->get(Clock::class);
        $session = $this->aSession('+3 days 10:00');
        $this->sessions->save($session);

        // Discarded on purpose: the bookings table doesn't exist until Task 14, so this
        // test only cares whether Session::book()'s mutation of $bookedSeats survives a
        // save()/clear()/reload round trip, not about persisting the Booking itself.
        $session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(3), $clock);
        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->sessions->find($session->id());
        self::assertNotNull($found);
        self::assertSame(3, $found->bookedSeats());
        self::assertSame(7, $found->availableSeats());
    }

    #[Test]
    public function it_checks_existence_by_experience_and_day(): void
    {
        $session = $this->aSession('+3 days 10:00');
        $this->sessions->save($session);

        self::assertTrue($this->sessions->existsForExperienceOn($session->experienceId(), $session->day()));
        self::assertFalse($this->sessions->existsForExperienceOn($session->experienceId(), SessionDay::fromString('2030-01-01')));
    }

    #[Test]
    public function unique_index_is_translated_to_domain_exception(): void
    {
        $this->sessions->save($this->aSession('+3 days 10:00'));

        $this->expectException(SessionAlreadyScheduledForDay::class);

        $this->sessions->save($this->aSession('+3 days 18:00'));
    }

    #[Test]
    public function find_for_update_returns_the_session_inside_a_transaction(): void
    {
        $session = $this->aSession('+3 days 10:00');
        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->entityManager->wrapInTransaction(fn(): ?Session => $this->sessions->findForUpdate($session->id()));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($session->id()));
    }

    private function aSession(string $when): Session
    {
        $clock = self::getContainer()->get(Clock::class);

        return Session::schedule(
            SessionId::generate(),
            \App\Experience\Domain\ExperienceId::fromString($this->experienceId),
            StartsAt::fromDateTime(new DateTimeImmutable($when, new DateTimeZone('UTC'))),
            Capacity::fromInt(10),
            Money::fromPrimitives(1500, 'EUR'),
            $clock,
        );
    }
}
