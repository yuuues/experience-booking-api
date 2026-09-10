<?php

declare(strict_types=1);

namespace App\Booking\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\NotFoundException;

final class BookingNotFound extends NotFoundException
{
    public static function withReference(BookingReference $reference): self
    {
        return new self(\sprintf('Booking <%s> not found.', $reference->value));
    }

    public function errorCode(): string
    {
        return 'booking-not-found';
    }
}
