<?php

declare(strict_types=1);

namespace App\Booking\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\DomainException;

final class BookingAlreadyCancelled extends DomainException
{
    public static function withReference(BookingReference $reference): self
    {
        return new self(\sprintf('Booking <%s> is already cancelled.', $reference->value));
    }

    public function errorCode(): string
    {
        return 'booking-already-cancelled';
    }
}
