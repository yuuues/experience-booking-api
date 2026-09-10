<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Experience\Domain\ExperienceId;

interface BookingRepository
{
    public function save(Booking $booking): void;

    public function findByReference(BookingReference $reference): ?Booking;

    public function existsByReference(BookingReference $reference): bool;

    /** True when any session of the experience has at least one confirmed booking. */
    public function existsConfirmedForExperience(ExperienceId $experienceId): bool;
}
