<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/**
 * P06 — annuaire « Nos professionnels » : artisans publics par ordre alphabétique, filtres de P02 (métiers, note,
 * labels) + département et initiale, pagination numérotée (`search.page_size`). Réponse : DirectoryResult.
 */
final readonly class DirectoryArtisans implements Query
{
    /**
     * @param list<string> $jobIds
     * @param list<string> $labels
     */
    public function __construct(
        public array $jobIds = [],
        public ?string $departmentCode = null,
        public ?string $letter = null,
        public array $labels = [],
        public ?float $minRating = null,
        public int $page = 1,
    ) {
    }
}
