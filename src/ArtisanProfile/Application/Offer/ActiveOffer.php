<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Offer;

use App\Shared\Application\Bus\Query;

/**
 * P09.3 « J'en profite » — offre publiée et en cours (RG-026) de cet artisan, rattachée à la demande directe.
 * Réponse : ?ActiveOfferView (null : offre inconnue, d'un autre artisan, non publiée ou expirée).
 */
final readonly class ActiveOffer implements Query
{
    public function __construct(public string $offerId, public string $artisanId)
    {
    }
}
