<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : prolonge l'horizon des créneaux ouverts (`availability.horizon_days`) et purge les créneaux passés. */
final readonly class RefreshAgendas implements Command
{
}
