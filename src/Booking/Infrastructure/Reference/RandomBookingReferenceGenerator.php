<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Reference;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** 8 random Crockford base32 chars (~1.1e12 combinations); uniqueness is enforced by the DB index. */
#[AsAlias(id: BookingReferenceGenerator::class)]
final class RandomBookingReferenceGenerator implements BookingReferenceGenerator
{
    public function next(): BookingReference
    {
        $alphabet = BookingReference::ALPHABET;
        $max = \strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < BookingReference::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return BookingReference::fromString(BookingReference::PREFIX . $code);
    }
}
