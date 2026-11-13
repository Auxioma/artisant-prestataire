<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/**
 * T-407 — une tâche planifiée : `expire` (RG-064 + E-19), `warn` (E-19 avant échéance), `remind` (E-19c), `purge`
 * (brouillons expirés, RG-060). Une transaction par tâche ; idempotente.
 */
final readonly class RunRequestMaintenance implements Command
{
    public const array TASKS = ['expire', 'warn', 'remind', 'purge'];

    public function __construct(public string $task)
    {
    }
}
