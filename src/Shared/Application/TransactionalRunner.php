<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface TransactionalRunner
{
    /**
     * Runs the operation atomically. Whatever it returns is returned; whatever it throws
     * rolls the transaction back and is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
