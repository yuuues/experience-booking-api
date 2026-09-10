<?php

declare(strict_types=1);

namespace App\Experience\Domain;

interface ExperienceRepository
{
    public function save(Experience $experience): void;

    public function find(ExperienceId $id): ?Experience;
}
