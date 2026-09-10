<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PriceRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $amount,
        #[Assert\Currency]
        public string $currency,
    ) {}
}
