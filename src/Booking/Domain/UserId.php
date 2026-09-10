<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Shared\Domain\Uuid;

/** Reference to an external user; not modelled here. */
final class UserId extends Uuid {}
