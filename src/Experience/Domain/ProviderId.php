<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\Uuid;

/** Reference to an external provider; the provider itself is not modelled here. */
final class ProviderId extends Uuid {}
