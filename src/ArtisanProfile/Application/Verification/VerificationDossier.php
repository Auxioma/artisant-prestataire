<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Query;

/** BO03.2 — dossier complet d'un artisan (données Sirene rappelées, contrôles, pièces, historique). Réponse : DossierView|null. */
final readonly class VerificationDossier implements Query
{
    public function __construct(public string $profileId)
    {
    }
}
