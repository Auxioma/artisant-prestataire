<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Shared\Application\Bus\Query;

/**
 * Photo d'un avis : lisible si l'avis est publié, ou par son auteur. Réponse : vrai si le fichier appartient à l'avis et
 * que l'utilisateur peut le voir.
 */
final readonly class ReviewPhoto implements Query
{
    public function __construct(public string $reviewId, public string $fileId, public ?string $userId)
    {
    }
}
