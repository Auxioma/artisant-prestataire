<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\Transaction\TransactionManager;
use Doctrine\DBAL\Connection;

final readonly class DbalTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }
}
