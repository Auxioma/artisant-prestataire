<?php

declare(strict_types=1);

namespace App\Administration\Application\Retention;

use App\Shared\Application\Bus\Command;

/** CDC §11 / §10 — purge des journaux au-delà de `audit.retention_months` (journaux de connexion : 1 an). */
final readonly class PurgeAuditLog implements Command
{
}
