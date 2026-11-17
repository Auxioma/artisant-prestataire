<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** BO03 — valider le dossier (RG-043). */
final readonly class ApproveArtisan implements Command
{
    public function __construct(public string $profileId, public string $adminId)
    {
    }
}
