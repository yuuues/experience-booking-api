<?php

declare(strict_types=1);

namespace App\Experience\Application\Find;

final readonly class FindExperienceQuery
{
    public function __construct(public string $id) {}
}
