<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

use App\Shared\Application\Bus\Query;

/** P02 — recherche d'artisans (RG-020, RG-021). Réponse : ArtisanSearchResult. */
final readonly class SearchArtisans implements Query
{
    public const string SORT_RELEVANCE = 'pertinence';
    public const string SORT_DISTANCE = 'distance';
    public const string SORT_RATING = 'note';
    public const string SORT_REVIEWS = 'avis';

    /**
     * @param list<string> $jobIds métiers recherchés (un artisan en exerce au moins un) ; vide = tous
     * @param list<string> $labels
     */
    public function __construct(
        public string $communeInsee,
        public ?int $radiusKm = null,
        public array $jobIds = [],
        public string $sort = self::SORT_RELEVANCE,
        public ?float $minRating = null,
        public array $labels = [],
        public int $page = 1,
        /** « Afficher plus » : renvoie toutes les pages jusqu'à `page` incluse (position conservée au retour). */
        public bool $cumulative = false,
    ) {
    }
}
