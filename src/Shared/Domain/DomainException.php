<?php

declare(strict_types=1);

namespace App\Shared\Domain;

abstract class DomainException extends \DomainException
{
    /** Stable, kebab-case identifier used as problem+json `type`. */
    abstract public function errorCode(): string;
}
