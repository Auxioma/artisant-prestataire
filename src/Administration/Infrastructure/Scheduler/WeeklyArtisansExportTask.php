<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Scheduler;

use App\Administration\Application\Exports\GenerateScheduledExport;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** BO15.2 — chaque lundi à 06 h 00 : export des artisans actifs (clé de la ScheduledExportSource du module). */
#[AsCronTask('0 6 * * 1', timezone: 'Europe/Paris')]
final readonly class WeeklyArtisansExportTask
{
    public const string KIND = 'artisans_actifs';

    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new GenerateScheduledExport(self::KIND));
    }
}
