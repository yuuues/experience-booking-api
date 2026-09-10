<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Session\Domain\StartsAt;
use App\Shared\Domain\InvalidValue;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StartsAtTest extends TestCase
{
    #[Test]
    public function it_normalizes_to_utc(): void
    {
        $startsAt = StartsAt::fromString('2026-10-01T10:00:00+02:00');

        self::assertSame('2026-10-01T08:00:00+00:00', $startsAt->toAtom());
    }

    #[Test]
    public function day_depends_on_platform_time_zone(): void
    {
        $startsAt = StartsAt::fromString('2026-10-01T23:30:00+00:00');

        self::assertSame('2026-10-02', $startsAt->dayIn(new DateTimeZone('Europe/Madrid'))->value);
        self::assertSame('2026-10-01', $startsAt->dayIn(new DateTimeZone('UTC'))->value);
    }

    #[Test]
    public function it_knows_the_cancellation_window(): void
    {
        $startsAt = StartsAt::fromString('2026-10-10T10:00:00+00:00');

        self::assertFalse($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-09T09:59:59+00:00'), 24));
        self::assertTrue($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-09T10:00:01+00:00'), 24));
        self::assertTrue($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-11T00:00:00+00:00'), 24));
    }

    #[Test]
    public function it_rejects_garbage(): void
    {
        $this->expectException(InvalidValue::class);

        StartsAt::fromString('next tuesday-ish');
    }
}
