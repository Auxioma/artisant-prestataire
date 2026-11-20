<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Shared\Application\Bus\Query;

/** PR09 — avis reçus par l'artisan connecté ; réponse : ArtisanReviewsView, ou null pour un autre compte. */
final readonly class ArtisanReviews implements Query
{
    /** Évolution de la note sur trois mois (PR09.1). */
    public const string TREND = '-3 months';

    public function __construct(public string $userId)
    {
    }
}
