<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final class InvalidValue extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid-value';
    }
}
