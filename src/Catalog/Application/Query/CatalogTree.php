<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/**
 * BO06.1 : arborescence complète (actifs et inactifs). Le handler renvoie
 * list<array{name: string, slug: string, root: bool, active: bool, featured: bool, icon: string, image: ?string, position: int,
 *            jobs: list<array{name: string, slug: string, active: bool, decennial: bool, synonyms: list<string>,
 *                             proposed: bool, replaced_by: ?string, artisans: int}>}>, catégories racines dans l'ordre d'affichage.
 */
final readonly class CatalogTree implements Query
{
}
