<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Scheduler;

use App\QuoteRequest\Application\Retention\ArchiveOldRequests;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : archivage des demandes closes au-delà de la durée de conservation (CDC §11). */
#[AsCronTask('45 3 * * *', timezone: 'Europe/Paris')]
final readonly class RequestRetentionTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new ArchiveOldRequests());
    }
}
