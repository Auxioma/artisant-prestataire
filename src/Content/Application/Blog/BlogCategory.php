<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

/** BO10.3 — catégorie du blog (filtre en pastille de la liste P15.1, rubrique de l'article). */
final readonly class BlogCategory
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public int $position,
        public int $publishedCount = 0,
    ) {
    }
}
