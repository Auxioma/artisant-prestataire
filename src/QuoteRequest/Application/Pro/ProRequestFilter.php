<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/** PR02.1 — filtres et tri de la liste des demandes disponibles. */
final readonly class ProRequestFilter
{
    public const string SORT_RECENT = 'recent';
    public const string SORT_CLOSEST = 'closest';
    public const string SORT_URGENT = 'urgent';
    public const array SORTS = [self::SORT_RECENT, self::SORT_CLOSEST, self::SORT_URGENT];

    public function __construct(
        public ?string $jobId = null,
        public ?int $maxKm = null,
        public ?string $timing = null,
        public ?int $sinceDays = null,
        public ?string $budget = null,
        public bool $directOnly = false,
        /** null : non lues et consultées ; sinon un `RequestState` (non lue, consultée, ignorée). */
        public ?RequestState $state = null,
        public string $sort = self::SORT_RECENT,
        /** Recherche libre : titre, métier, commune, code postal, numéro. */
        public string $search = '',
    ) {
    }
}
