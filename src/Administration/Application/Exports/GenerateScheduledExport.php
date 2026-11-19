<?php

declare(strict_types=1);

namespace App\Administration\Application\Exports;

use App\Shared\Application\Bus\Command;

/** Produit l'export planifié $kind (clé d'une ScheduledExportSource) et détruit les exports expirés. */
final readonly class GenerateScheduledExport implements Command
{
    public function __construct(public string $kind)
    {
    }
}
