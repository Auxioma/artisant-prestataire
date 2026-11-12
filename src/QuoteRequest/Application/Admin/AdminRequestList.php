<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

use App\Shared\Application\Bus\Query;

/**
 * BO04.1 — demandes filtrées (recherche par n° ou métier, statut, métier, département, source, score au-delà du seuil).
 * Réponse : AdminRequestPage.
 */
final readonly class AdminRequestList implements Query
{
    public function __construct(
        public string $search = '',
        public ?string $status = null,
        public ?string $jobId = null,
        public ?string $department = null,
        public ?string $source = null,
        public ?int $minRisk = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }
}
