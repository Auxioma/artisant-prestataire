<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Completion;

use App\Shared\Application\Bus\Query;

/** PR08.1 — jauge de complétude de l'artisan connecté. Réponse : ProfileCompletion, ou null sans profil. */
final readonly class ProfileCompletionOf implements Query
{
    public function __construct(public string $userId)
    {
    }
}
