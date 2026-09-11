<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\BookingStatus;
use App\Experience\Domain\ExperienceId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: BookingRepository::class)]
final readonly class DoctrineBookingRepository implements BookingRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Booking $booking): void
    {
        $this->entityManager->persist($booking);
        $this->entityManager->flush();
    }

    public function findByReference(BookingReference $reference): ?Booking
    {
        // BookingReferenceType::convertToDatabaseValue() requires a BookingReference instance
        // (it throws on a bare scalar), so the criteria value must be the value object, not
        // its primitive ->value.
        return $this->entityManager->getRepository(Booking::class)->findOneBy(['reference' => $reference]);
    }

    public function findByReferenceForUpdate(BookingReference $reference): ?Booking
    {
        // A copy already in the identity map would come back from the query unchanged — Doctrine
        // never overwrites a managed entity from a result set — so the row lock would be taken but
        // its state ignored. Refreshing that copy is not an option either: ORM 3.7 refuses to re-set
        // an initialised readonly property (LogicException), and the aggregate keeps its value objects
        // in them. So evict any such copy first; the locked query then hydrates the row as it is once
        // the lock is granted.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Booking::class] ?? [] as $managed) {
            if ($managed instanceof Booking && $managed->reference()->equals($reference)) {
                $this->entityManager->detach($managed);
            }
        }

        $booking = $this->entityManager->createQuery('SELECT b FROM App\Booking\Domain\Booking b WHERE b.reference = :reference')
            ->setParameter('reference', $reference)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $booking instanceof Booking ? $booking : null;
    }

    public function existsByReference(BookingReference $reference): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(b.id) FROM App\Booking\Domain\Booking b WHERE b.reference = :reference',
        )
            ->setParameter('reference', $reference)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function existsConfirmedForExperience(ExperienceId $experienceId): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(b.id) FROM App\Booking\Domain\Booking b
             JOIN App\Session\Domain\Session s WITH s.id = b.sessionId
             WHERE s.experienceId = :experienceId AND b.status = :status',
        )
            ->setParameter('experienceId', $experienceId)
            ->setParameter('status', BookingStatus::Confirmed)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
