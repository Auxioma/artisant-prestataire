<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

final readonly class SearchCandidate
{
    /** @param list<string> $labels */
    public function __construct(
        public string $profileId,
        public string $name,
        public ?string $shortDescription,
        public ?string $mainJobId,
        public bool $mainJobMatches,
        public ?string $communeName,
        public ?string $departmentCode,
        public float $distanceKm,
        public ?float $ratingAverage,
        public int $reviewsCount,
        public ?float $responseRate,
        public int $completion,
        public bool $available,
        public array $labels,
        public bool $hasLogo,
    ) {
    }
}
