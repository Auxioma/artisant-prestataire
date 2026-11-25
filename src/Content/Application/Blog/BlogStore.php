<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

interface BlogStore
{
    /** @return list<BlogCategory> par position, avec le nombre d'articles publiés à `$now` */
    public function categories(\DateTimeImmutable $now): array;

    public function category(string $id): ?BlogCategory;

    public function categoryBySlug(string $slug): ?BlogCategory;

    public function categorySlugTaken(string $slug, ?string $exceptId): bool;

    public function saveCategory(string $id, string $slug, string $name, int $position, \DateTimeImmutable $at): void;

    public function deleteCategory(string $id): void;

    public function find(string $id): ?BlogArticle;

    public function findBySlug(string $slug): ?BlogArticle;

    public function slugTaken(string $slug, ?string $exceptId): bool;

    /** @return list<BlogArticle> tous les articles (BO), les plus récents d'abord */
    public function all(): array;

    /** @return list<BlogArticle> articles publiés à `$now`, à la une puis du plus récent au plus ancien */
    public function published(\DateTimeImmutable $now, ?string $categoryId, int $offset, int $limit): array;

    public function countPublished(\DateTimeImmutable $now, ?string $categoryId): int;

    /** @return list<BlogArticle> articles publiés de la même catégorie (puis des autres), hors `$article` */
    public function related(BlogArticle $article, \DateTimeImmutable $now, int $limit): array;

    public function create(string $id, string $slug, string $title, ?string $adminId, \DateTimeImmutable $at): void;

    /**
     * @param array{slug: string, title: string, short_title: ?string, excerpt: ?string, category_id: ?string, author_name: ?string,
     *              author_media_id: ?string, cover_media_id: ?string, content_html: string, job_ids: list<string>, featured: bool,
     *              meta_title: ?string, meta_description: ?string} $fields
     */
    public function save(string $id, array $fields, ?string $adminId, \DateTimeImmutable $at): void;

    public function setPublishedAt(string $id, ?\DateTimeImmutable $publishedAt, ?string $adminId, \DateTimeImmutable $at): void;

    public function delete(string $id): void;

    /** @return list<string> titres des articles qui utilisent l'image (couverture ou portrait de l'auteur) */
    public function usingMedia(string $mediaId): array;
}
