<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Experience\Domain\ExperienceId;

interface BookingRepository
{
    public function save(Booking $booking): void;

    /** Unlocked read, for queries. Never decide a write on it: see findByReferenceForUpdate(). */
    public function findByReference(BookingReference $reference): ?Booking;

    /**
     * Locks the booking row until the surrounding transaction ends and returns it as it is once the
     * lock is granted, even if this unit of work already holds an older copy of it. Any write that
     * depends on the booking's state must use the instance returned here.
     */
    public function findByReferenceForUpdate(BookingReference $reference): ?Booking;

    public function existsByReference(BookingReference $reference): bool;

    /** True when any session of the experience has at least one confirmed booking. */
    public function existsConfirmedForExperience(ExperienceId $experienceId): bool;
}
