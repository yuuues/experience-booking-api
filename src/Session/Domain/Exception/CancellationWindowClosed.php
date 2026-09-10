<?php

declare(strict_types=1);

namespace App\Session\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\DomainException;

final class CancellationWindowClosed extends DomainException
{
    public static function for(BookingReference $reference, StartsAt $startsAt): self
    {
        return new self(\sprintf('Booking <%s> cannot be cancelled: less than 24 hours before the session start (%s).', $reference->value, $startsAt->toAtom()));
    }

    public function errorCode(): string
    {
        return 'cancellation-window-closed';
    }
}
