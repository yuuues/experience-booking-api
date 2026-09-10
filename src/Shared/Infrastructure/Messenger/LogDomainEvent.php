<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\DomainEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Catch-all audit trail: every domain event gets at least one handler, so none lands in the failed transport. */
#[AsMessageHandler]
final readonly class LogDomainEvent
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(DomainEvent $event): void
    {
        $this->logger->info('Domain event handled', ['event' => $event::eventName(), 'aggregateId' => $event->aggregateId()]);
    }
}
