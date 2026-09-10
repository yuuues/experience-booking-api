<?php

declare(strict_types=1);

namespace App\Experience\Application\Find;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;

final readonly class FindExperienceHandler
{
    public function __construct(private ExperienceRepository $experiences) {}

    public function __invoke(FindExperienceQuery $query): ExperienceResponse
    {
        $id = ExperienceId::fromString($query->id);
        $experience = $this->experiences->find($id) ?? throw ExperienceNotFound::withId($id);

        return ExperienceResponse::fromExperience($experience);
    }
}
