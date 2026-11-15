<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Scheduler;

use App\Administration\Application\Retention\PurgeAuditLog;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : purge des journaux au-delà de la durée de conservation (CDC §11). */
#[AsCronTask('30 3 * * *', timezone: 'Europe/Paris')]
final readonly class RetentionTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new PurgeAuditLog());
    }
}
