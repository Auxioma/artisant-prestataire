<?php

declare(strict_types=1);

namespace App\Shared\Application\Billing;

use App\Shared\Application\Bus\Query;

/**
 * Raison sociale, siège et n° de TVA d'un artisan (PR12.2 : informations de facturation pré-remplies). Traitée par
 * ArtisanProfile ; réponse : ArtisanBillingIdentityDetails, ou null sans profil professionnel.
 */
final readonly class ArtisanBillingIdentity implements Query
{
    public function __construct(public string $userId)
    {
    }
}
