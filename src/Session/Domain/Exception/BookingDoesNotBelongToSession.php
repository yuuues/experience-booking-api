<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Session\Domain\SessionId;
use App\Shared\Domain\DomainException;

final class BookingDoesNotBelongToSession extends DomainException
{
    public static function for(BookingReference $reference, SessionId $sessionId): self
    {
        return new self(\sprintf('Booking <%s> does not belong to session <%s>.', $reference->value, $sessionId->value));
    }

    public function errorCode(): string
    {
        return 'booking-does-not-belong-to-session';
    }
}
