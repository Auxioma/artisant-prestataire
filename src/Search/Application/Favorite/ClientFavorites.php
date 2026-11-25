<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

use App\Shared\Application\Bus\Query;

/** C05 — favoris du client, recherche, filtre métier / ville, tri ; réponse : ClientFavoritesView. */
final readonly class ClientFavorites implements Query
{
    public const array SORTS = ['ajout' => 'Date d’ajout', 'note' => 'Note'];

    public function __construct(public string $userId, public string $query = '', public string $jobId = '', public string $city = '', public string $sort = 'ajout')
    {
    }
}
