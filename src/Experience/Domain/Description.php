<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\InvalidValue;

final readonly class Description
{
    public const int MAX_LENGTH = 2000;

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ('' === $value) {
            throw new InvalidValue('Description cannot be blank.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidValue(\sprintf('Description cannot exceed %d characters.', self::MAX_LENGTH));
        }

        return new self($value);
    }
}
