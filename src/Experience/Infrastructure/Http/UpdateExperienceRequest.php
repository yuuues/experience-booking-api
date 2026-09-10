<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Domain\Description;
use App\Experience\Domain\Title;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateExperienceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Title::MAX_LENGTH)]
        public string $title,
        #[Assert\NotBlank]
        #[Assert\Length(max: Description::MAX_LENGTH)]
        public string $description,
    ) {}
}
