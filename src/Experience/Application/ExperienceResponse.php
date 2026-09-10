<?php

declare(strict_types=1);

namespace App\Experience\Application;

use App\Experience\Domain\Experience;

final readonly class ExperienceResponse
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $providerId,
    ) {}

    public static function fromExperience(Experience $experience): self
    {
        return new self(
            $experience->id()->value,
            $experience->title()->value,
            $experience->description()->value,
            $experience->providerId()->value,
        );
    }
}
