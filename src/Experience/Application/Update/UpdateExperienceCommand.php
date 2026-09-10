<?php

declare(strict_types=1);

namespace App\Experience\Application\Update;

final readonly class UpdateExperienceCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
    ) {}
}
