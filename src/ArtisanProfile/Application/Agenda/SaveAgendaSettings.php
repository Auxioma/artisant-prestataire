<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/** PR06.1 / PR06.2 — durée des créneaux, délai de prévenance, fermeture les jours fériés. */
final readonly class SaveAgendaSettings implements Command
{
    public function __construct(public string $userId, public int $slotMinutes, public int $minNoticeHours, public bool $closedOnHolidays)
    {
    }
}
