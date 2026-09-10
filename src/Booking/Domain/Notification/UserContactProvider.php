<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Booking\Domain\UserId;

/** Resolves the contact email of a user living in another bounded context. */
interface UserContactProvider
{
    public function emailFor(UserId $userId): Email;
}
