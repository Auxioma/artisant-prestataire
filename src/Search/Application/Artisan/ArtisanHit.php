<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

final readonly class ArtisanHit
{
    public function __construct(public SearchCandidate $artisan, public float $score, public float $bayesRating)
    {
    }
}
