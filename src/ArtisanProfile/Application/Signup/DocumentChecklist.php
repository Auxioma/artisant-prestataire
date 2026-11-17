<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Query;

/** A03.6 — pièces attendues et état des dépôts. Réponse : list<DocumentSlot> (vide sans profil). */
final readonly class DocumentChecklist implements Query
{
    public function __construct(public string $userId)
    {
    }
}
