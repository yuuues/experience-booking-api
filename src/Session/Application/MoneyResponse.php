<?php

declare(strict_types=1);

namespace App\Session\Application;

use App\Shared\Domain\Money;

final readonly class MoneyResponse
{
    public function __construct(public int $amount, public string $currency) {}

    public static function fromMoney(Money $money): self
    {
        return new self($money->amount, $money->currency);
    }
}
