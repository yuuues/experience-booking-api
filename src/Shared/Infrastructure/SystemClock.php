<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(id: Clock::class)]
final readonly class SystemClock implements Clock
{
    public function __construct(
        #[Autowire(param: 'app.timezone')]
        private string $timeZone,
    ) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timeZone);
    }
}
