<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Application\TransactionalRunner;

final class InMemoryTransactionalRunner implements TransactionalRunner
{
    public int $transactions = 0;

    /** True for the duration of the callable passed to run(), false otherwise. */
    public bool $inTransaction = false;

    public function run(callable $operation): mixed
    {
        ++$this->transactions;
        $this->inTransaction = true;

        try {
            return $operation();
        } finally {
            $this->inTransaction = false;
        }
    }
}
