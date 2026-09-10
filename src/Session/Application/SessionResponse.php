<?php

declare(strict_types=1);

namespace App\Session\Application;

use App\Session\Domain\Session;

final readonly class SessionResponse
{
    public function __construct(
        public string $id,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        public int $bookedSeats,
        public int $availableSeats,
        public MoneyResponse $price,
    ) {}

    public static function fromSession(Session $session): self
    {
        return new self(
            $session->id()->value,
            $session->experienceId()->value,
            $session->startsAt()->toAtom(),
            $session->capacity()->value,
            $session->bookedSeats(),
            $session->availableSeats(),
            MoneyResponse::fromMoney($session->price()),
        );
    }
}
