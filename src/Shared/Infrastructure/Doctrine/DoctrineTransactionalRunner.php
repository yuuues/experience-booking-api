<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\TransactionalRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: TransactionalRunner::class)]
final readonly class DoctrineTransactionalRunner implements TransactionalRunner
{
    private const string LOCK_TIMEOUT = '2000ms';

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function run(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($operation): mixed {
            // Fail fast instead of piling up requests on a hot session row; mapped to 503 by the exception listener.
            $this->entityManager->getConnection()->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

            return $operation();
        });
    }
}
