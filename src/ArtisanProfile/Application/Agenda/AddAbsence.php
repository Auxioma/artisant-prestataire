<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/** PR16 « Indisponibilités » / PR19 « Mes congés » — journées sans créneau (dates Y-m-d incluses). */
final readonly class AddAbsence implements Command
{
    public function __construct(public string $userId, public string $absenceId, public string $from, public string $to, public string $kind, public ?string $label = null)
    {
    }
}
