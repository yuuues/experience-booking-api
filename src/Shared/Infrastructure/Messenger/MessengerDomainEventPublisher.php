<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\DomainEvent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(id: DomainEventPublisher::class)]
final readonly class MessengerDomainEventPublisher implements DomainEventPublisher
{
    public function __construct(private MessageBusInterface $messageBus) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->messageBus->dispatch($event);
        }
    }
}
