<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Notification\SentNotificationRegistry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: SentNotificationRegistry::class)]
final readonly class DbalSentNotificationRegistry implements SentNotificationRegistry
{
    public function __construct(private Connection $connection) {}

    public function wasSent(BookingReference $reference, string $type): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM sent_notifications WHERE booking_reference = :reference AND type = :type',
            ['reference' => $reference->value, 'type' => $type],
        );
    }

    public function markSent(BookingReference $reference, string $type): void
    {
        try {
            $this->connection->insert('sent_notifications', [
                'booking_reference' => $reference->value,
                'type' => $type,
                'sent_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already marked by a concurrent delivery; nothing to do.
        }
    }
}
