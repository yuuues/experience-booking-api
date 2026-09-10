<?php

declare(strict_types=1);

namespace App\Experience\Application\Register;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Description;
use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\ProviderId;
use App\Experience\Domain\Title;
use App\Shared\Application\DomainEventPublisher;

final readonly class RegisterExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private DomainEventPublisher $events,
    ) {}

    public function __invoke(RegisterExperienceCommand $command): ExperienceResponse
    {
        $experience = Experience::register(
            ExperienceId::fromString($command->id),
            Title::fromString($command->title),
            Description::fromString($command->description),
            ProviderId::fromString($command->providerId),
        );

        $this->experiences->save($experience);
        $this->events->publish(...$experience->pullDomainEvents());

        return ExperienceResponse::fromExperience($experience);
    }
}
