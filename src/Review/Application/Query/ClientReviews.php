<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Shared\Application\Bus\Query;

/** C06 — prestations à évaluer (fenêtre RG-034 ouverte) et avis du client ; réponse : ClientReviewsView. */
final readonly class ClientReviews implements Query
{
    public function __construct(public string $userId)
    {
    }
}
