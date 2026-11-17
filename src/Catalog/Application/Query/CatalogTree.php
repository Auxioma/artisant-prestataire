<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/**
 * BO06.1 : arborescence complète (actifs et inactifs). Le handler renvoie
 * list<array{name: string, slug: string, root: bool, active: bool, featured: bool,
 *            jobs: list<array{name: string, slug: string, active: bool, decennial: bool, synonyms: list<string>}>}>.
 */
final readonly class CatalogTree implements Query
{
}
