<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Session;

use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;

final class InMemorySessionRepository implements SessionRepository
{
    /** @var array<string, Session> */
    private array $items = [];

    public int $lockedReads = 0;

    public function save(Session $session): void
    {
        $this->items[$session->id()->value] = $session;
    }

    public function find(SessionId $id): ?Session
    {
        return $this->items[$id->value] ?? null;
    }

    public function findForUpdate(SessionId $id): ?Session
    {
        ++$this->lockedReads;

        return $this->find($id);
    }

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool
    {
        foreach ($this->items as $session) {
            if ($session->experienceId()->equals($experienceId) && $session->day()->equals($day)) {
                return true;
            }
        }

        return false;
    }
}
