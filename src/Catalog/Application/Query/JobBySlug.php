<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** Métier proposé au public par son slug (URL de recherche, pages SEO). Réponse : JobIndexEntry|null. */
final readonly class JobBySlug implements Query
{
    public function __construct(public string $slug)
    {
    }
}
