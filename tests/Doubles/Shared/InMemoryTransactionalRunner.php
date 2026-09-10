<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Application\TransactionalRunner;

final class InMemoryTransactionalRunner implements TransactionalRunner
{
    public int $transactions = 0;

    public function run(callable $operation): mixed
    {
        ++$this->transactions;

        return $operation();
    }
}
