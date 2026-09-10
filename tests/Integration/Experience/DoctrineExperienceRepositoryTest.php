<?php

declare(strict_types=1);

namespace App\Tests\Integration\Experience;

use App\Experience\Domain\Description;
use App\Experience\Domain\ExperienceEditability;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\Title;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineExperienceRepositoryTest extends KernelTestCase
{
    private ExperienceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ExperienceRepository::class);
    }

    #[Test]
    public function it_persists_and_rehydrates(): void
    {
        $experience = ExperienceTest::anExperience();

        $this->repository->save($experience);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $found = $this->repository->find($experience->id());
        self::assertNotNull($found);
        self::assertSame('Kayak', $found->title()->value);
        self::assertTrue($found->providerId()->equals($experience->providerId()));
    }

    #[Test]
    public function it_updates_mutable_fields(): void
    {
        $experience = ExperienceTest::anExperience();
        $this->repository->save($experience);

        $experience->update(Title::fromString('Renamed'), Description::fromString('New'), ExperienceEditability::editable());
        $this->repository->save($experience);
        self::getContainer()->get('doctrine')->getManager()->clear();

        self::assertSame('Renamed', $this->repository->find($experience->id())?->title()->value);
    }

    #[Test]
    public function it_returns_null_when_missing(): void
    {
        self::assertNull($this->repository->find(ExperienceId::generate()));
    }
}
