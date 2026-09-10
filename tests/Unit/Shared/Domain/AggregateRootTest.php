<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregateRootTest extends TestCase
{
    #[Test]
    public function itRecordsAndReleasesEventsOnce(): void
    {
        $aggregate = new class extends AggregateRoot {
            public function doSomething(): void
            {
                $this->record(new SomethingHappened('agg-1'));
            }
        };

        $aggregate->doSomething();
        $aggregate->doSomething();

        $events = $aggregate->pullDomainEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(SomethingHappened::class, $events[0]);
        self::assertSame([], $aggregate->pullDomainEvents());
    }
}

final readonly class SomethingHappened implements DomainEvent
{
    public function __construct(private string $id) {}

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public static function eventName(): string
    {
        return 'something.happened';
    }
}
