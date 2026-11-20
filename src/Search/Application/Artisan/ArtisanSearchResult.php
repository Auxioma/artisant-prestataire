<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

final readonly class ArtisanSearchResult
{
    /**
     * @param list<ArtisanHit> $hits
     * @param list<string>     $labels labels déclarés par les artisans de la zone (filtre P02.3)
     */
    public function __construct(public array $hits, public int $total, public int $page, public int $perPage, public int $radiusKm, public array $labels = [])
    {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }
}
