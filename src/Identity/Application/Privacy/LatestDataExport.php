<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Query;

/** Réponse : ?DataExportView (dernière demande du titulaire). */
final readonly class LatestDataExport implements Query
{
    public function __construct(public string $userId)
    {
    }
}
