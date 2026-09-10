<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Domain;

use App\Experience\Domain\Description;
use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceEditability;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ProviderId;
use App\Experience\Domain\Title;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExperienceTest extends TestCase
{
    #[Test]
    public function it_registers_and_records_event(): void
    {
        $id = ExperienceId::generate();
        $provider = ProviderId::generate();

        $experience = Experience::register($id, Title::fromString('Kayak'), Description::fromString('At dawn'), $provider);

        self::assertTrue($experience->id()->equals($id));
        self::assertSame('Kayak', $experience->title()->value);
        self::assertSame('At dawn', $experience->description()->value);
        self::assertTrue($experience->providerId()->equals($provider));

        $events = $experience->pullDomainEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ExperienceRegistered::class, $events[0]);
        self::assertSame($id->value, $events[0]->aggregateId());
    }

    #[Test]
    public function it_updates_when_editable(): void
    {
        $experience = self::anExperience();
        $experience->pullDomainEvents();

        $experience->update(Title::fromString('New'), Description::fromString('Desc'), ExperienceEditability::editable());

        self::assertSame('New', $experience->title()->value);
        self::assertInstanceOf(ExperienceUpdated::class, $experience->pullDomainEvents()[0]);
    }

    #[Test]
    public function it_refuses_update_when_locked_by_bookings(): void
    {
        $experience = self::anExperience();

        $this->expectException(ExperienceHasBookings::class);

        $experience->update(Title::fromString('New'), Description::fromString('Desc'), ExperienceEditability::locked());
    }

    public static function anExperience(): Experience
    {
        return Experience::register(ExperienceId::generate(), Title::fromString('Kayak'), Description::fromString('At dawn'), ProviderId::generate());
    }
}
