<?php

declare(strict_types=1);

namespace App\Session\Application\Schedule;

final readonly class ScheduleSessionCommand
{
    public function __construct(
        public string $id,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        public int $priceAmount,
        public string $priceCurrency,
    ) {}
}
