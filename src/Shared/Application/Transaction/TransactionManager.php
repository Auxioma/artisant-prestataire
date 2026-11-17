<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

/** Exécute un traitement applicatif hors command bus dans une transaction unique (imports, lots). */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
