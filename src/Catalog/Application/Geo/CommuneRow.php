<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo;

/** Commune validée, prête à l'écriture (slug attribué). */
final readonly class CommuneRow
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
        public float $latitude,
        public float $longitude,
    ) {
    }
}
