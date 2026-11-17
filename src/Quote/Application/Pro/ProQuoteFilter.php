<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

/** PR05.1 — onglet (`devis` ou `chantiers`), recherche, statut, période (jours) et métier. */
final readonly class ProQuoteFilter
{
    public const string TAB_QUOTES = 'devis';
    public const string TAB_WORKS = 'chantiers';
    public const array PERIODS = ['30' => 30, '90' => 90, '365' => 365];

    public function __construct(
        public string $tab = self::TAB_QUOTES,
        public ?string $q = null,
        public ?string $status = null,
        public ?int $days = null,
        public ?string $jobId = null,
    ) {
    }
}
