<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-01T10:00:00+00:00', private readonly string $timeZone = 'Europe/Madrid')
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timeZone);
    }

    public function travelTo(string $now): void
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }
}
