<?php

declare(strict_types=1);

namespace App\Session\Application\Find;

final readonly class FindSessionQuery
{
    public function __construct(public string $id) {}
}
