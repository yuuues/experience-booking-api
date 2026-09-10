<?php

declare(strict_types=1);

namespace App\Experience\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class ExperienceRegistered implements DomainEvent
{
    public function __construct(
        public string $experienceId,
        public string $providerId,
        public string $title,
        private DateTimeImmutable $occurredOn = new DateTimeImmutable(),
    ) {}

    public function aggregateId(): string
    {
        return $this->experienceId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'experience.registered';
    }
}
