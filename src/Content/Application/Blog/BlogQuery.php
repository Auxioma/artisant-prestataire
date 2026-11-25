<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Shared\Application\Bus\Query;

/**
 * Lectures du blog : catégories, article (id ou adresse), liste publiée paginée (catégorie facultative), articles liés,
 * liste complète du BO.
 */
final readonly class BlogQuery implements Query
{
    private function __construct(
        public string $kind,
        public ?string $id = null,
        public ?string $slug = null,
        public ?string $categoryId = null,
        public int $page = 1,
        public int $perPage = 10,
    ) {
    }

    public static function categories(): self
    {
        return new self('categories');
    }

    public static function category(string $slug): self
    {
        return new self('category', slug: $slug);
    }

    public static function article(string $id): self
    {
        return new self('article', id: $id);
    }

    public static function articleBySlug(string $slug): self
    {
        return new self('article_slug', slug: $slug);
    }

    public static function published(?string $categoryId, int $page, int $perPage): self
    {
        return new self('published', categoryId: $categoryId, page: max(1, $page), perPage: max(1, $perPage));
    }

    public static function related(string $id, int $limit): self
    {
        return new self('related', id: $id, perPage: max(1, $limit));
    }

    public static function all(): self
    {
        return new self('all');
    }
}
