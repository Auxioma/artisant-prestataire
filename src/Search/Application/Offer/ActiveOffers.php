<?php

declare(strict_types=1);

namespace App\Search\Application\Offer;

use App\Shared\Application\Bus\Query;

/**
 * P09 — offres actives (publiées, dans leur période : RG-026) filtrées par métiers (catégorie), lieu (commune +
 * rayon) et type. Réponse : list<OfferCard>, fin la plus proche d'abord.
 */
final readonly class ActiveOffers implements Query
{
    /** @param list<string> $jobIds */
    public function __construct(public array $jobIds = [], public ?string $communeInsee = null, public int $radiusKm = 30, public ?string $type = null, public int $limit = 48)
    {
    }
}
