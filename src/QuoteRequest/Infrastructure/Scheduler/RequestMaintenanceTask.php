<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Scheduler;

use App\QuoteRequest\Application\Maintenance\RunRequestMaintenance;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque heure : échéances RG-064, prévenances E-19, relances E-19c, purge des brouillons — une transaction par tâche. */
#[AsCronTask('10 * * * *', timezone: 'Europe/Paris')]
final readonly class RequestMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        foreach (RunRequestMaintenance::TASKS as $task) {
            $this->commands->dispatch(new RunRequestMaintenance($task));
        }
    }
}
