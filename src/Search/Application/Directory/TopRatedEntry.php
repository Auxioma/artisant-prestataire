<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Search\Application\Artisan\SearchCandidate;

/** Ligne du classement P07 : rang, note bayésienne (RG-024), moyenne brute, nombre d'avis sur la période, dernier avis. */
final readonly class TopRatedEntry
{
    public function __construct(
        public int $rank,
        public SearchCandidate $artisan,
        public float $bayesRating,
        public float $average,
        public int $reviewsCount,
        public ?string $lastReview,
    ) {
    }
}
