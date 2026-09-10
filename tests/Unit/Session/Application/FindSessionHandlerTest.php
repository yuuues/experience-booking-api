<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Session\Application\Find\FindSessionHandler;
use App\Session\Application\Find\FindSessionQuery;
use App\Session\Domain\Exception\SessionNotFound;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindSessionHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_the_session(): void
    {
        $sessions = new InMemorySessionRepository();
        $session = SessionTest::aSession(new FixedClock());
        $sessions->save($session);

        $response = (new FindSessionHandler($sessions))(new FindSessionQuery($session->id()->value));

        self::assertSame($session->id()->value, $response->id);
        self::assertSame(10, $response->capacity);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(SessionNotFound::class);

        (new FindSessionHandler(new InMemorySessionRepository()))(new FindSessionQuery('0192b3a4-1234-7abc-8def-0123456789aa'));
    }
}
