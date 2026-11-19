<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/** CDC §11 — justificatifs détruits un délai après la fin de la relation (compte effacé ou dossier refusé). */
final readonly class PurgeEndedRelationDocuments implements Command
{
}
