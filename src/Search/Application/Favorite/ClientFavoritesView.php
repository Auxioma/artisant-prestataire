<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

final readonly class ClientFavoritesView
{
    /**
     * @param list<FavoriteView> $items favoris filtrés et triés
     * @param list<FavoriteView> $all   tous les favoris (compteurs, filtres)
     */
    public function __construct(public array $items, public array $all)
    {
    }

    public function available(): int
    {
        return \count(array_filter($this->all, static fn (FavoriteView $f): bool => $f->active && $f->available));
    }

    public function delayed(): int
    {
        return \count(array_filter($this->all, static fn (FavoriteView $f): bool => $f->active && !$f->available));
    }

    /** @return list<string> métiers présents dans les favoris */
    public function jobIds(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (FavoriteView $f): array => $f->jobIds, $this->all))));
    }

    /** @return list<string> */
    public function cities(): array
    {
        $cities = array_values(array_unique(array_filter(array_map(static fn (FavoriteView $f): ?string => $f->communeName, $this->all))));
        sort($cities);

        return $cities;
    }
}
