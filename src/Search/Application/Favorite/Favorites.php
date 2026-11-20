<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

/** Favoris d'un client (`search.favorite`). */
interface Favorites
{
    /** Vrai si l'artisan existe et est public (ajout) — un favori existant reste listé même si l'artisan devient inactif. */
    public function add(string $userId, string $artisanId, \DateTimeImmutable $at): bool;

    public function remove(string $userId, string $artisanId): void;

    public function has(string $userId, string $artisanId): bool;

    /**
     * Favoris du client avec les données publiques de l'artisan (null si l'artisan n'est plus public).
     *
     * @return list<FavoriteView>
     */
    public function of(string $userId): array;

    /** RG-090 : tous les favoris d'un compte effacé. */
    public function forget(string $userId): void;
}
