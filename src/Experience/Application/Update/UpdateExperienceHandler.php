<?php

declare(strict_types=1);

namespace App\Experience\Application\Update;

use App\Booking\Domain\BookingRepository;
use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Description;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceEditability;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\Title;
use App\Shared\Application\DomainEventPublisher;

final readonly class UpdateExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private BookingRepository $bookings,
        private DomainEventPublisher $events,
    ) {}

    public function __invoke(UpdateExperienceCommand $command): ExperienceResponse
    {
        $id = ExperienceId::fromString($command->id);
        $experience = $this->experiences->find($id) ?? throw ExperienceNotFound::withId($id);

        // The fact comes from the Booking module; the rule is enforced by the aggregate.
        $editability = ExperienceEditability::fromHasConfirmedBookings($this->bookings->existsConfirmedForExperience($id));
        $experience->update(Title::fromString($command->title), Description::fromString($command->description), $editability);

        $this->experiences->save($experience);
        $this->events->publish(...$experience->pullDomainEvents());

        return ExperienceResponse::fromExperience($experience);
    }
}
