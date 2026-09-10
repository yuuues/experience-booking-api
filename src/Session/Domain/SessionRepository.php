<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Experience\Domain\ExperienceId;

interface SessionRepository
{
    public function save(Session $session): void;

    public function find(SessionId $id): ?Session;

    /** Same as find() but takes a row-level write lock; must be called inside a transaction. */
    public function findForUpdate(SessionId $id): ?Session;

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool;
}
