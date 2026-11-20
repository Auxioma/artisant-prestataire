<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Scheduler;

use App\Administration\Application\Exports\GenerateScheduledExport;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** BO15.2 — le 1er de chaque mois à 07 h 00 : export des factures du mois précédent (clé de la ScheduledExportSource du module). */
#[AsCronTask('0 7 1 * *', timezone: 'Europe/Paris')]
final readonly class MonthlyInvoicesExportTask
{
    public const string KIND = 'factures_mois';

    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new GenerateScheduledExport(self::KIND));
    }
}
