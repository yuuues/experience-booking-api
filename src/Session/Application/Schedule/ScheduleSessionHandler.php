<?php

declare(strict_types=1);

namespace App\Session\Application\Schedule;

use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Session\Application\SessionResponse;
use App\Session\Domain\Capacity;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Session\Domain\StartsAt;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;

final readonly class ScheduleSessionHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private SessionRepository $sessions,
        private Clock $clock,
        private DomainEventPublisher $events,
    ) {}

    public function __invoke(ScheduleSessionCommand $command): SessionResponse
    {
        $experienceId = ExperienceId::fromString($command->experienceId);
        $this->experiences->find($experienceId) ?? throw ExperienceNotFound::withId($experienceId);

        $startsAt = StartsAt::fromString($command->startsAt);
        $day = $startsAt->dayIn($this->clock->timeZone());
        // Cross-aggregate invariant: checked here, guaranteed by the unique index (experience_id, day).
        if ($this->sessions->existsForExperienceOn($experienceId, $day)) {
            throw SessionAlreadyScheduledForDay::on($experienceId, $day);
        }

        $session = Session::schedule(
            SessionId::fromString($command->id),
            $experienceId,
            $startsAt,
            Capacity::fromInt($command->capacity),
            Money::fromPrimitives($command->priceAmount, $command->priceCurrency),
            $this->clock,
        );

        $this->sessions->save($session);
        $this->events->publish(...$session->pullDomainEvents());

        return SessionResponse::fromSession($session);
    }
}
