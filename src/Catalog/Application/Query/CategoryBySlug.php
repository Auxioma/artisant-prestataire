<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** P04 — catégorie racine active par son slug. Réponse : CategoryPage|null. */
final readonly class CategoryBySlug implements Query
{
    public function __construct(public string $slug)
    {
    }
}
