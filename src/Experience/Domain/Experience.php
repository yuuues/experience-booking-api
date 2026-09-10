<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Shared\Domain\AggregateRoot;

final class Experience extends AggregateRoot
{
    private function __construct(
        private readonly ExperienceId $id,
        private Title $title,
        private Description $description,
        private readonly ProviderId $providerId,
    ) {}

    public static function register(ExperienceId $id, Title $title, Description $description, ProviderId $providerId): self
    {
        $experience = new self($id, $title, $description, $providerId);
        $experience->record(new ExperienceRegistered($id->value, $providerId->value, $title->value));

        return $experience;
    }

    public function update(Title $title, Description $description, ExperienceEditability $editability): void
    {
        if (!$editability->isEditable()) {
            throw ExperienceHasBookings::withId($this->id);
        }

        $this->title = $title;
        $this->description = $description;
        $this->record(new ExperienceUpdated($this->id->value, $this->providerId->value, $title->value));
    }

    public function id(): ExperienceId
    {
        return $this->id;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function description(): Description
    {
        return $this->description;
    }

    public function providerId(): ProviderId
    {
        return $this->providerId;
    }
}
