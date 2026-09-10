<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** A request that clashes with the current state of the resource: mapped to HTTP 409. */
abstract class ConflictException extends DomainException {}
