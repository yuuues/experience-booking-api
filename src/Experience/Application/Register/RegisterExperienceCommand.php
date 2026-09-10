<?php

declare(strict_types=1);

namespace App\Experience\Application\Register;

final readonly class RegisterExperienceCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $providerId,
    ) {}
}
