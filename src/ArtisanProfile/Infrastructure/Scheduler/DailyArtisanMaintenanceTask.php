<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Scheduler;

use App\ArtisanProfile\Application\Agenda\RefreshAgendas;
use App\ArtisanProfile\Application\Maintenance\HideUninsuredProfiles;
use App\ArtisanProfile\Application\Maintenance\SendDocumentExpiryAlerts;
use App\ArtisanProfile\Application\Maintenance\SendSignupReminders;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque matin : alertes d'échéance (RG-032), masquage des comptes non assurés (RG-130), relances E-23, prolongation des créneaux de l'agenda (PR06) — une transaction par tâche. */
#[AsCronTask('15 7 * * *', timezone: 'Europe/Paris')]
final readonly class DailyArtisanMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new SendDocumentExpiryAlerts());
        $this->commands->dispatch(new HideUninsuredProfiles());
        $this->commands->dispatch(new SendSignupReminders());
        $this->commands->dispatch(new RefreshAgendas());
    }
}
