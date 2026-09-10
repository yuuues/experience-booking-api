<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use DateTimeInterface;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScheduleSessionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\DateTime(format: DateTimeInterface::ATOM, message: 'Use ISO 8601 with offset, e.g. 2026-10-01T10:00:00+02:00.')]
        public string $startsAt,
        #[Assert\Positive]
        public int $capacity,
        #[Assert\Valid]
        public PriceRequest $price,
    ) {}
}
