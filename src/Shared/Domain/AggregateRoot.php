<?php

declare(strict_types=1);

namespace App\Shared\Domain;

abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    /** @return list<DomainEvent> */
    final public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    final protected function record(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }
}
