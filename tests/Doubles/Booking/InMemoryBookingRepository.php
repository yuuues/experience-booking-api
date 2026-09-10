<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\SessionRepository;

final class InMemoryBookingRepository implements BookingRepository
{
    /** @var array<string, Booking> keyed by reference */
    private array $items = [];

    public function __construct(private readonly ?SessionRepository $sessions = null) {}

    public function save(Booking $booking): void
    {
        $this->items[$booking->reference()->value] = $booking;
    }

    public function findByReference(BookingReference $reference): ?Booking
    {
        return $this->items[$reference->value] ?? null;
    }

    public function existsByReference(BookingReference $reference): bool
    {
        return isset($this->items[$reference->value]);
    }

    public function existsConfirmedForExperience(ExperienceId $experienceId): bool
    {
        foreach ($this->items as $booking) {
            if ($booking->isCancelled()) {
                continue;
            }
            $session = $this->sessions?->find($booking->sessionId());
            if (null !== $session && $session->experienceId()->equals($experienceId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Booking> */
    public function all(): array
    {
        return array_values($this->items);
    }
}
