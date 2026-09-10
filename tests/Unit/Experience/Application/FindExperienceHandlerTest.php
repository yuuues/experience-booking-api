<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Experience\Application\Find\FindExperienceHandler;
use App\Experience\Application\Find\FindExperienceQuery;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindExperienceHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_response_dto(): void
    {
        $repository = new InMemoryExperienceRepository();
        $experience = ExperienceTest::anExperience();
        $repository->save($experience);

        $response = (new FindExperienceHandler($repository))(new FindExperienceQuery($experience->id()->value));

        self::assertSame($experience->id()->value, $response->id);
        self::assertSame('Kayak', $response->title);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(ExperienceNotFound::class);

        (new FindExperienceHandler(new InMemoryExperienceRepository()))(new FindExperienceQuery('0192b3a4-1234-7abc-8def-0123456789ab'));
    }
}
