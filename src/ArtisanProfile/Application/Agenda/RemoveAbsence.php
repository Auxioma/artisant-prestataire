<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

final readonly class RemoveAbsence implements Command
{
    public function __construct(public string $userId, public string $absenceId)
    {
    }
}
