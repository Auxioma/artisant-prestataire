<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

final readonly class ArtisanSearchResult
{
    /** @param list<ArtisanHit> $hits */
    public function __construct(public array $hits, public int $total, public int $page, public int $perPage, public int $radiusKm)
    {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }
}
