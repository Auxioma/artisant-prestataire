<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Application\Privacy\ExpireDataExports;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : destruction des archives « Mes données » dont le lien de 7 jours a expiré. */
#[AsCronTask('50 4 * * *', timezone: 'Europe/Paris')]
final readonly class DataExportMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new ExpireDataExports());
    }
}
