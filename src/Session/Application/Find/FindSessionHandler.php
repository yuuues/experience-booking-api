<?php

declare(strict_types=1);

namespace App\Session\Application\Find;

use App\Session\Application\SessionResponse;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;

final readonly class FindSessionHandler
{
    public function __construct(private SessionRepository $sessions) {}

    public function __invoke(FindSessionQuery $query): SessionResponse
    {
        $id = SessionId::fromString($query->id);
        $session = $this->sessions->find($id) ?? throw SessionNotFound::withId($id);

        return SessionResponse::fromSession($session);
    }
}
