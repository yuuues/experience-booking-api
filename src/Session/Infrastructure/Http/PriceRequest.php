<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Shared\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class PriceRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        #[Assert\LessThanOrEqual(Money::MAX_AMOUNT)]
        public int $amount,
        #[Assert\Currency]
        public string $currency,
    ) {}
}
