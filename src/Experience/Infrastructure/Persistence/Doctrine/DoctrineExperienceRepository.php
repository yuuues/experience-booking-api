<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine;

use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: ExperienceRepository::class)]
final readonly class DoctrineExperienceRepository implements ExperienceRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Experience $experience): void
    {
        $this->entityManager->persist($experience);
        $this->entityManager->flush();
    }

    public function find(ExperienceId $id): ?Experience
    {
        // ExperienceIdType::convertToDatabaseValue() requires a Uuid instance (it throws on a
        // bare scalar), so the identifier passed to find() must be the value object, not its
        // primitive ->value.
        return $this->entityManager->find(Experience::class, $id);
    }
}
