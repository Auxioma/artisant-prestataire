<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

/** Page de la liste publiée (P15.1) : articles et total pour la pagination numérotée. */
final readonly class BlogPage
{
    /** @param list<BlogArticle> $articles */
    public function __construct(public array $articles, public int $total, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
