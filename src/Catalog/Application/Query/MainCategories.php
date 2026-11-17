<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/**
 * Méga-menu et P03 : catégories racines actives, dans l'ordre du BO, avec leurs premiers métiers actifs
 * (métiers rattachés à la catégorie ou à ses sous-catégories) ; catégories sans métier actif exclues.
 */
final readonly class MainCategories implements Query
{
    /** Clé de cache du méga-menu (invalidée sur tout événement du référentiel). */
    public const string MENU_CACHE_KEY = 'catalog.main_categories.menu';

    public function __construct(public int $jobsPerCategory, public bool $featuredOnly = false)
    {
    }
}
