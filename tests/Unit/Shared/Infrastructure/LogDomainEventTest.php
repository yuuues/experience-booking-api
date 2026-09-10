<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Booking\Domain\Event\BookingConfirmed;
use App\Shared\Infrastructure\Messenger\LogDomainEvent;
use App\Tests\Doubles\Shared\InMemoryLogger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LogDomainEventTest extends TestCase
{
    #[Test]
    public function it_logs_the_event_name_and_aggregate_id_once(): void
    {
        $logger = new InMemoryLogger();
        $handler = new LogDomainEvent($logger);
        $event = new BookingConfirmed(
            reference: 'BK-7F3A2C9K',
            bookingId: '0192b3a4-1234-7abc-8def-0123456789b1',
            sessionId: '0192b3a4-1234-7abc-8def-0123456789b2',
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: 2,
            totalAmount: 3000,
            totalCurrency: 'EUR',
            occurredOn: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );

        $handler($event);

        $records = $logger->records();
        self::assertCount(1, $records);
        self::assertSame(LogLevel::INFO, $records[0]['level']);
        self::assertSame('Domain event handled', $records[0]['message']);
        self::assertSame('booking.confirmed', $records[0]['context']['event']);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789b1', $records[0]['context']['aggregateId']);
    }
}
