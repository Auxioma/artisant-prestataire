<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/** RG-032 — alertes J-30 / J-7 / J0 (E-25) et passage des pièces échues à « expirée ». Tâche quotidienne, idempotente. */
final readonly class SendDocumentExpiryAlerts implements Command
{
}
