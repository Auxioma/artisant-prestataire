<?php

declare(strict_types=1);

namespace App\Search\Application\Offer;

use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ActiveOffersHandler implements QueryHandler
{
    public function __construct(private ArtisanSearchIndex $index)
    {
    }

    /** @return list<OfferCard> */
    public function __invoke(ActiveOffers $query): array
    {
        $artisanIds = null;
        if (null !== $query->communeInsee) {
            $artisanIds = array_map(static fn ($c): string => $c->profileId, $this->index->candidates($query->communeInsee, $query->radiusKm, [], []));
            if ([] === $artisanIds) {
                return [];
            }
        }

        return $this->index->activeOffers($artisanIds, $query->jobIds, $query->type, $query->limit);
    }
}
