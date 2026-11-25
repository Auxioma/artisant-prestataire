<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** Catégories illustrées par cette image de la médiathèque (réponse : list<string> libellés « Catégorie X »). */
final readonly class CategoriesUsingMedia implements Query
{
    public function __construct(public string $mediaId)
    {
    }
}
