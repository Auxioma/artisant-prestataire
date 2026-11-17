<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/**
 * Couverture d'une zone d'intervention (A03.4) : communes actives dans le rayon autour du centroïde d'une commune
 * (RG-022), ou appartenant aux départements / communes listés. Réponse : Coverage.
 */
final readonly class AreaCoverage implements Query
{
    /**
     * @param list<string> $departments
     * @param list<string> $communes
     */
    public function __construct(
        public ?string $centerInsee = null,
        public ?int $radiusKm = null,
        public array $departments = [],
        public array $communes = [],
    ) {
    }
}
