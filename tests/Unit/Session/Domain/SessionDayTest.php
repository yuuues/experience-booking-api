<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Session\Domain\SessionDay;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionDayTest extends TestCase
{
    #[Test]
    public function it_rejects_an_impossible_calendar_date(): void
    {
        $this->expectException(InvalidValue::class);

        SessionDay::fromString('2026-02-31');
    }

    #[Test]
    public function it_accepts_a_valid_leap_day(): void
    {
        $day = SessionDay::fromString('2028-02-29');

        self::assertSame('2028-02-29', $day->value);
    }
}
