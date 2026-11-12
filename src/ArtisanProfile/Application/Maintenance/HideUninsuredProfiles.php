<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/** RG-130 — masquage des comptes vérifiés dont une assurance obligatoire est échue depuis plus du délai de grâce. Tâche quotidienne, idempotente. */
final readonly class HideUninsuredProfiles implements Command
{
}
