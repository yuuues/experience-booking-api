<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use Psr\Log\AbstractLogger;
use Stringable;

final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: mixed, message: string, context: array<mixed>}> */
    public function records(): array
    {
        return $this->records;
    }
}
