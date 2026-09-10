<?php

declare(strict_types=1);

namespace App\Session\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class SessionScheduled implements DomainEvent
{
    public function __construct(
        public string $sessionId,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        private DateTimeImmutable $occurredOn,
    ) {}

    public function aggregateId(): string
    {
        return $this->sessionId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'session.scheduled';
    }
}
