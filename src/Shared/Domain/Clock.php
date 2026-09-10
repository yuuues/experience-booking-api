<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;
use DateTimeZone;

interface Clock
{
    /** Current instant, always in UTC. */
    public function now(): DateTimeImmutable;

    /** Platform time zone used for calendar-day rules. */
    public function timeZone(): DateTimeZone;
}
