<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Retention;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : demandes closes depuis plus de `requests.retention_years` archivées (CDC §11). */
final readonly class ArchiveOldRequests implements Command
{
}
