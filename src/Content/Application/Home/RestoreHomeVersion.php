<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Restaure une version de l'historique dans le brouillon (à republier). */
final readonly class RestoreHomeVersion implements Command
{
    public function __construct(public string $adminId, public string $versionId)
    {
    }
}
