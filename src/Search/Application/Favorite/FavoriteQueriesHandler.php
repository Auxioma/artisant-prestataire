<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

use App\Shared\Application\Bus\QueryHandler;

final readonly class FavoriteQueriesHandler implements QueryHandler
{
    public function __construct(private Favorites $favorites)
    {
    }

    public function __invoke(ClientFavorites $query): ClientFavoritesView
    {
        $all = $this->favorites->of($query->userId);
        $q = mb_strtolower(trim($query->query));
        $items = array_values(array_filter($all, static fn (FavoriteView $f): bool => ('' === $q || str_contains(mb_strtolower($f->name.' '.$f->communeName), $q))
            && ('' === $query->jobId || \in_array($query->jobId, $f->jobIds, true))
            && ('' === $query->city || $f->communeName === $query->city)));
        if ('note' === $query->sort) {
            usort($items, static fn (FavoriteView $a, FavoriteView $b): int => [$b->rating ?? 0, $b->reviewsCount] <=> [$a->rating ?? 0, $a->reviewsCount]);
        }

        return new ClientFavoritesView($items, $all);
    }
}
