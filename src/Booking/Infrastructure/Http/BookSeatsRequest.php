<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BookSeatsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,
        #[Assert\Positive]
        public int $seats,
    ) {}
}
