<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Session\Application\Schedule\ScheduleSessionCommand;
use App\Session\Application\Schedule\ScheduleSessionHandler;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Exception\SessionInThePast;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScheduleSessionHandlerTest extends TestCase
{
    private InMemoryExperienceRepository $experiences;
    private InMemorySessionRepository $sessions;
    private InMemoryDomainEventPublisher $events;
    private ScheduleSessionHandler $handler;
    private string $experienceId;

    protected function setUp(): void
    {
        $this->experiences = new InMemoryExperienceRepository();
        $this->sessions = new InMemorySessionRepository();
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new ScheduleSessionHandler($this->experiences, $this->sessions, new FixedClock('2026-10-01T10:00:00+00:00'), $this->events);

        $experience = ExperienceTest::anExperience();
        $this->experiences->save($experience);
        $this->experienceId = $experience->id()->value;
    }

    #[Test]
    public function it_schedules_a_session(): void
    {
        $response = ($this->handler)($this->command(startsAt: '2026-10-05T12:00:00+02:00'));

        self::assertSame($this->experienceId, $response->experienceId);
        self::assertSame('2026-10-05T10:00:00+00:00', $response->startsAt);
        self::assertSame(10, $response->availableSeats);
        self::assertSame(1500, $response->price->amount);
        self::assertCount(1, $this->events->publishedOf(SessionScheduled::class));
    }

    #[Test]
    public function it_rejects_unknown_experience(): void
    {
        $this->expectException(ExperienceNotFound::class);

        ($this->handler)($this->command(experienceId: '0192b3a4-1234-7abc-8def-0123456789ff'));
    }

    #[Test]
    public function it_rejects_two_sessions_same_day_in_platform_time_zone(): void
    {
        ($this->handler)($this->command(startsAt: '2026-10-05T23:30:00+00:00')); // 2026-10-06 in Madrid

        $this->expectException(SessionAlreadyScheduledForDay::class);

        ($this->handler)($this->command(startsAt: '2026-10-06T08:00:00+02:00')); // also 2026-10-06 in Madrid
    }

    #[Test]
    public function it_rejects_past_dates(): void
    {
        $this->expectException(SessionInThePast::class);

        ($this->handler)($this->command(startsAt: '2026-09-30T10:00:00+00:00'));
    }

    private function command(?string $experienceId = null, string $startsAt = '2026-10-05T10:00:00+00:00'): ScheduleSessionCommand
    {
        return new ScheduleSessionCommand(
            id: '0192b3a4-1234-7abc-8def-0123456789aa',
            experienceId: $experienceId ?? $this->experienceId,
            startsAt: $startsAt,
            capacity: 10,
            priceAmount: 1500,
            priceCurrency: 'EUR',
        );
    }
}
