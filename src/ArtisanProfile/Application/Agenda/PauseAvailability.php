<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/** PR16 « Statut de disponibilité » — mode pause : ni demande directe ni badge « Disponible » (PR06.1). */
final readonly class PauseAvailability implements Command
{
    public function __construct(public string $userId, public bool $paused)
    {
    }
}
