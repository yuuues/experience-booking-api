<?php

declare(strict_types=1);

namespace App\Tests\Integration\Booking;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Session\Domain\Capacity;
use App\Session\Domain\Session;
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

final class DoctrineBookingRepositoryTest extends KernelTestCase
{
    private BookingRepository $bookings;
    private SessionRepository $sessions;
    private EntityManagerInterface $entityManager;
    private Clock $clock;
    private ExperienceId $experienceId;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->bookings = $container->get(BookingRepository::class);
        $this->sessions = $container->get(SessionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->clock = $container->get(Clock::class);

        $experience = ExperienceTest::anExperience();
        $container->get(ExperienceRepository::class)->save($experience);
        $this->experienceId = $experience->id();

        $this->session = Session::schedule(
            SessionId::generate(),
            $this->experienceId,
            StartsAt::fromDateTime(new DateTimeImmutable('+3 days', new DateTimeZone('UTC'))),
            Capacity::fromInt(10),
            Money::fromPrimitives(1500, 'EUR'),
            $this->clock,
        );
        $this->sessions->save($this->session);
    }

    #[Test]
    public function it_persists_rehydrates_and_finds_by_reference(): void
    {
        $booking = $this->session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        $this->entityManager->clear();

        $found = $this->bookings->findByReference(BookingReference::fromString('BK-7F3A2C9K'));
        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($booking->id()));
        self::assertTrue($found->reference()->equals($booking->reference()));
        self::assertTrue($found->sessionId()->equals($this->session->id()));
        self::assertTrue($found->userId()->equals($booking->userId()));
        self::assertSame(2, $found->seats()->value);
        self::assertSame(3000, $found->totalPrice()->amount);
        self::assertSame('EUR', $found->totalPrice()->currency);
        self::assertSame('confirmed', $found->status()->value);
        self::assertSame($booking->bookedAt()->getTimestamp(), $found->bookedAt()->getTimestamp());
        self::assertSame('+00:00', $found->bookedAt()->getTimezone()->getName());
        self::assertNull($found->cancelledAt());
        self::assertTrue($this->bookings->existsByReference(BookingReference::fromString('BK-7F3A2C9K')));
        self::assertFalse($this->bookings->existsByReference(BookingReference::fromString('BK-00000000')));
    }

    #[Test]
    public function it_persists_and_rehydrates_a_cancelled_booking(): void
    {
        $booking = $this->session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);

        $this->session->cancelBooking($booking, $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        $this->entityManager->clear();

        $found = $this->bookings->findByReference(BookingReference::fromString('BK-7F3A2C9K'));
        self::assertNotNull($found);
        self::assertSame(\App\Booking\Domain\BookingStatus::Cancelled, $found->status());

        $bookingCancelledAt = $booking->cancelledAt();
        $foundCancelledAt = $found->cancelledAt();
        self::assertNotNull($bookingCancelledAt);
        self::assertNotNull($foundCancelledAt);
        self::assertSame($bookingCancelledAt->getTimestamp(), $foundCancelledAt->getTimestamp());
    }

    #[Test]
    public function find_by_reference_for_update_returns_the_committed_row_even_when_the_booking_is_already_managed(): void
    {
        $reference = BookingReference::fromString('BK-7F3A2C9K');
        $booking = $this->session->book(BookingId::generate(), $reference, UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        $this->entityManager->clear();

        // Managed and confirmed in this unit of work, like a cancellation that read the booking early.
        $early = $this->bookings->findByReference($reference);
        self::assertNotNull($early);
        self::assertFalse($early->isCancelled());

        // A concurrent cancellation commits behind the identity map's back.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE bookings SET status = 'cancelled', cancelled_at = now() WHERE reference = :reference",
            ['reference' => $reference->value],
        );

        $locked = $this->entityManager->wrapInTransaction(fn(): ?Booking => $this->bookings->findByReferenceForUpdate($reference));

        // A stale answer here is exactly what let two cancellations release the same seats.
        self::assertNotNull($locked);
        self::assertTrue($locked->isCancelled());
        self::assertNotNull($locked->cancelledAt());
    }

    #[Test]
    public function find_by_reference_for_update_returns_null_for_an_unknown_reference(): void
    {
        $found = $this->entityManager->wrapInTransaction(fn(): ?Booking => $this->bookings->findByReferenceForUpdate(BookingReference::fromString('BK-00000000')));

        self::assertNull($found);
    }

    #[Test]
    public function it_detects_confirmed_bookings_for_an_experience(): void
    {
        self::assertFalse($this->bookings->existsConfirmedForExperience($this->experienceId));

        $booking = $this->session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        self::assertTrue($this->bookings->existsConfirmedForExperience($this->experienceId));

        $this->session->cancelBooking($booking, $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        self::assertFalse($this->bookings->existsConfirmedForExperience($this->experienceId));
    }
}
