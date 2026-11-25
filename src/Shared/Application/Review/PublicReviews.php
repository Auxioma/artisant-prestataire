<?php

declare(strict_types=1);

namespace App\Shared\Application\Review;

use App\Shared\Application\Bus\Query;

/**
 * P10.9 — avis certifiés publiés d'un artisan : synthèse et liste triée / filtrée, paginée par 10. Traitée par Review ;
 * réponse : PublicReviewsView.
 */
final readonly class PublicReviews implements Query
{
    public const array SORTS = ['recents' => 'Plus récents', 'meilleurs' => 'Meilleures notes', 'moins-bons' => 'Moins bonnes notes'];
    public const array FILTERS = ['toutes' => 'Toutes les notes', '5' => '5 étoiles', '4' => '4 étoiles', '3-et-moins' => '3 et moins'];
    public const int PAGE = 10;

    public function __construct(public string $artisanId, public string $sort = 'recents', public string $filter = 'toutes', public int $limit = self::PAGE)
    {
    }
}
