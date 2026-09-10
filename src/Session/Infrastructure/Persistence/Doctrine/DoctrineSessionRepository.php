<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine;

use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: SessionRepository::class)]
final readonly class DoctrineSessionRepository implements SessionRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Session $session): void
    {
        try {
            $this->entityManager->persist($session);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // uniq_sessions_experience_day: the use case pre-checks, the index closes the race.
            throw SessionAlreadyScheduledForDay::on($session->experienceId(), $session->day());
        }
    }

    public function find(SessionId $id): ?Session
    {
        // SessionIdType::convertToDatabaseValue() requires a Uuid instance (it throws on a
        // bare scalar), so the identifier passed to find() must be the value object, not its
        // primitive ->value.
        return $this->entityManager->find(Session::class, $id);
    }

    public function findForUpdate(SessionId $id): ?Session
    {
        return $this->entityManager->find(Session::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(s.id) FROM App\Session\Domain\Session s WHERE s.experienceId = :experienceId AND s.day = :day',
        )
            ->setParameter('experienceId', $experienceId)
            ->setParameter('day', $day)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
