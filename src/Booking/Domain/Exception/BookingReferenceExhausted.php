<?php

declare(strict_types=1);

namespace App\Booking\Domain\Exception;

use App\Shared\Domain\ConflictException;

/**
 * Raised when the reference generator could not produce an unused booking reference within the
 * bounded attempt budget. Expected to be exceptionally rare; the client can safely retry the
 * request, which is why this is modelled as a conflict rather than a hard failure.
 */
final class BookingReferenceExhausted extends ConflictException
{
    public static function afterAttempts(int $attempts): self
    {
        return new self(\sprintf('Could not generate a unique booking reference after %d attempt(s).', $attempts));
    }

    public function errorCode(): string
    {
        return 'booking-reference-exhausted';
    }
}
