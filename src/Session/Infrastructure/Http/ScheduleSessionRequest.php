<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Domain\Capacity;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScheduleSessionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        // Deliberately looser than DateTimeInterface::ATOM: that format never matches what a
        // real client sends, because JavaScript's toISOString(), Python's isoformat() and most
        // HTTP clients emit a "Z" UTC designator and/or fractional seconds by default, and ATOM
        // requires an exact "+HH:MM" offset with no fraction. Both are valid ISO 8601; the
        // domain (StartsAt::fromString) already parses either one via `new DateTimeImmutable()`,
        // so the request-level check only needs to require an explicit zone — never a bare
        // local time, which would be ambiguous given we store everything in UTC.
        #[Assert\Regex(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/',
            message: 'Use an ISO 8601 date-time with an explicit UTC designator or numeric offset, e.g. 2026-10-01T10:00:00Z or 2026-10-01T10:00:00+02:00.',
        )]
        public string $startsAt,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(Capacity::MAX)]
        public int $capacity,
        #[Assert\Valid]
        public PriceRequest $price,
    ) {}
}
