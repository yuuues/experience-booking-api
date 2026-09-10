<?php

declare(strict_types=1);

namespace App\Experience\Domain;

/**
 * Whether an experience may still be edited. The fact (are there confirmed bookings?)
 * lives in the Booking module; the use case resolves it and the aggregate enforces it.
 */
final readonly class ExperienceEditability
{
    private function __construct(private bool $editable) {}

    public static function editable(): self
    {
        return new self(true);
    }

    public static function locked(): self
    {
        return new self(false);
    }

    public static function fromHasConfirmedBookings(bool $hasConfirmedBookings): self
    {
        return new self(!$hasConfirmedBookings);
    }

    public function isEditable(): bool
    {
        return $this->editable;
    }
}
