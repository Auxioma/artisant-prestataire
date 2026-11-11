<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo;

/** Commune telle que fournie par la source (non encore validée). */
final readonly class CommuneRecord
{
    /**
     * @param list<string> $postalCodes
     */
    public function __construct(
        public string $insee,
        public string $name,
        public array $postalCodes,
        public string $departmentCode,
        public ?int $population,
        public float $latitude,
        public float $longitude,
    ) {
    }
}
