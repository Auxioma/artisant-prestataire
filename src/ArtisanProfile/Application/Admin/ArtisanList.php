<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO03.1 — comptes professionnels filtrés (raison sociale, nom commercial, SIRET ; statut), paginés. Réponse : ArtisanListPage. */
final readonly class ArtisanList implements Query
{
    public function __construct(public string $search = '', public ?string $status = null, public int $page = 1, public int $perPage = 25)
    {
    }
}
