<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;

final class InMemoryMailer implements MailerPort
{
    /** @var list<BookingEmail> */
    private array $sent = [];

    public function send(BookingEmail $email): void
    {
        $this->sent[] = $email;
    }

    /** @return list<BookingEmail> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
