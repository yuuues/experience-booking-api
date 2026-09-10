<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\BookingStatus;
use App\Experience\Domain\ExperienceId;
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
