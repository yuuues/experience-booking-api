<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

interface MailerPort
{
    public function send(BookingEmail $email): void;
}
