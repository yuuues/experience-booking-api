<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Contact;

use App\Booking\Domain\Notification\Email;
use App\Booking\Domain\Notification\UserContactProvider;
use App\Booking\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Users are not modelled in this service; a real adapter would call the user/identity context. */
#[AsAlias(id: UserContactProvider::class)]
final class FakeUserContactProvider implements UserContactProvider
{
    public function emailFor(UserId $userId): Email
    {
        return Email::fromString(\sprintf('user-%s@example.test', $userId->value));
    }
}
