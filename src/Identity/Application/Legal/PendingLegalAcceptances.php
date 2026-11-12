<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Shared\Application\Bus\Query;

/**
 * RG-002 — versions en vigueur que l'utilisateur doit (ré)accepter avant de continuer : CGU (et CGV professionnelles
 * pour un artisan) dont une version « substantielle » n'a pas encore été acceptée. Réponse : list<LegalDocumentVersion>.
 */
final readonly class PendingLegalAcceptances implements Query
{
    public function __construct(public string $userId)
    {
    }
}
