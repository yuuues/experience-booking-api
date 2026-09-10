<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final readonly class StartsAt
{
    /** @param DateTimeImmutable $value always UTC */
    private function __construct(public DateTimeImmutable $value) {}

    public static function fromString(string $iso8601): self
    {
        try {
            $value = new DateTimeImmutable($iso8601);
        } catch (Exception) {
            throw new InvalidValue(\sprintf('<%s> is not a valid date-time.', $iso8601));
        }

        return self::fromDateTime($value);
    }

    public static function fromDateTime(DateTimeImmutable $value): self
    {
        return new self($value->setTimezone(new DateTimeZone('UTC')));
    }

    public function dayIn(DateTimeZone $timeZone): SessionDay
    {
        return SessionDay::fromString($this->value->setTimezone($timeZone)->format('Y-m-d'));
    }

    public function isAtOrBefore(DateTimeImmutable $moment): bool
    {
        return $this->value <= $moment;
    }

    /** True when `moment` is later than `hours` before the start (i.e. inside the closing window, or after start). */
    public function isWithinHoursBefore(DateTimeImmutable $moment, int $hours): bool
    {
        return $moment > $this->value->sub(new DateInterval(\sprintf('PT%dH', $hours)));
    }

    public function toAtom(): string
    {
        return $this->value->format(\DATE_ATOM);
    }
}
