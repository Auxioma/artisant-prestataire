<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class BlogQueryHandler implements QueryHandler
{
    public function __construct(private BlogStore $blog, private Clock $clock)
    {
    }

    /** @return list<BlogCategory>|list<BlogArticle>|BlogCategory|BlogArticle|BlogPage|null */
    public function __invoke(BlogQuery $query): array|BlogCategory|BlogArticle|BlogPage|null
    {
        $now = $this->clock->now();

        return match ($query->kind) {
            'categories' => $this->blog->categories($now),
            'category' => $this->blog->categoryBySlug((string) $query->slug),
            'article' => $this->blog->find((string) $query->id),
            'article_slug' => $this->blog->findBySlug((string) $query->slug),
            'published' => new BlogPage(
                $this->blog->published($now, $query->categoryId, ($query->page - 1) * $query->perPage, $query->perPage),
                $this->blog->countPublished($now, $query->categoryId),
                $query->page,
                $query->perPage,
            ),
            'related' => null === ($article = $this->blog->find((string) $query->id)) ? [] : $this->blog->related($article, $now, $query->perPage),
            default => $this->blog->all(),
        };
    }
}
