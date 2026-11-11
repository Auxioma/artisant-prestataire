<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

final readonly class CommuneSummary
{
    /**
     * @param list<string> $postalCodes
     */
    public function __construct(
        public string $insee,
        public string $name,
        public string $slug,
        public array $postalCodes,
        public string $departmentCode,
        public ?int $population,
        public ?float $distanceKm = null,
    ) {
    }
}
