<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\DomainEvent;

final class InMemoryDomainEventPublisher implements DomainEventPublisher
{
    /** @var list<DomainEvent> */
    private array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }

    /** @return list<DomainEvent> */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * @template T of DomainEvent
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function publishedOf(string $class): array
    {
        return array_values(array_filter($this->published, static fn(DomainEvent $e): bool => $e instanceof $class));
    }
}
