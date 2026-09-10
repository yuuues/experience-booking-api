<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Experience\Application\Register\RegisterExperienceCommand;
use App\Experience\Application\Register\RegisterExperienceHandler;
use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\InvalidValue;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterExperienceHandlerTest extends TestCase
{
    private InMemoryExperienceRepository $repository;
    private InMemoryDomainEventPublisher $events;
    private RegisterExperienceHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryExperienceRepository();
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new RegisterExperienceHandler($this->repository, $this->events);
    }

    #[Test]
    public function it_registers_persists_and_publishes(): void
    {
        $command = new RegisterExperienceCommand(
            id: '0192b3a4-1234-7abc-8def-0123456789ab',
            title: 'Kayak at dawn',
            description: 'Two hours paddling.',
            providerId: '0192b3a4-1234-7abc-8def-0123456789ac',
        );

        $response = ($this->handler)($command);

        self::assertSame($command->id, $response->id);
        self::assertSame('Kayak at dawn', $response->title);
        self::assertNotNull($this->repository->find(ExperienceId::fromString($command->id)));
        self::assertCount(1, $this->events->publishedOf(ExperienceRegistered::class));
    }

    #[Test]
    public function it_rejects_blank_title(): void
    {
        $this->expectException(InvalidValue::class);

        ($this->handler)(new RegisterExperienceCommand('0192b3a4-1234-7abc-8def-0123456789ab', ' ', 'x', '0192b3a4-1234-7abc-8def-0123456789ac'));
    }
}
