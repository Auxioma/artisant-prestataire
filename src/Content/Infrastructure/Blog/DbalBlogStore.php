<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Blog;

use App\Content\Application\Blog\BlogArticle;
use App\Content\Application\Blog\BlogCategory;
use App\Content\Application\Blog\BlogStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalBlogStore implements BlogStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SELECT = <<<'SQL'
        SELECT a.*, array_to_json(a.job_ids) AS job_ids_json, c.slug AS category_slug, c.name AS category_name, c.position AS category_position
        FROM content.blog_article a
        LEFT JOIN content.blog_category c ON c.id = a.category_id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function categories(\DateTimeImmutable $now): array
    {
        return array_map(static fn (array $r): BlogCategory => new BlogCategory(
            DbalValue::string($r['id']),
            DbalValue::string($r['slug']),
            DbalValue::string($r['name']),
            DbalValue::int($r['position']),
            DbalValue::int($r['published']),
        ), $this->connection->fetchAllAssociative(
            'SELECT c.*, (SELECT count(*) FROM content.blog_article a WHERE a.category_id = c.id AND a.published_at <= :now) AS published
             FROM content.blog_category c ORDER BY c.position, c.name',
            ['now' => $now->format(\DATE_ATOM)],
        ));
    }

    public function category(string $id): ?BlogCategory
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM content.blog_category WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::categoryView($row);
    }

    public function categoryBySlug(string $slug): ?BlogCategory
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM content.blog_category WHERE slug = :s', ['s' => $slug]);

        return false === $row ? null : self::categoryView($row);
    }

    public function categorySlugTaken(string $slug, ?string $exceptId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM content.blog_category WHERE slug = :s AND id IS DISTINCT FROM :id', ['s' => $slug, 'id' => $exceptId]);
    }

    public function saveCategory(string $id, string $slug, string $name, int $position, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.blog_category (id, slug, name, position, created_at) VALUES (:id, :slug, :name, :position, :at)
             ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name, position = EXCLUDED.position',
            ['id' => $id, 'slug' => $slug, 'name' => $name, 'position' => $position, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function deleteCategory(string $id): void
    {
        $this->connection->delete('content.blog_category', ['id' => $id]);
    }

    public function find(string $id): ?BlogArticle
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE a.id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function findBySlug(string $slug): ?BlogArticle
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE a.slug = :s', ['s' => $slug]);

        return false === $row ? null : self::view($row);
    }

    public function slugTaken(string $slug, ?string $exceptId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM content.blog_article WHERE slug = :s AND id IS DISTINCT FROM :id', ['s' => $slug, 'id' => $exceptId]);
    }

    public function all(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative(self::SELECT.' ORDER BY coalesce(a.published_at, a.updated_at) DESC'));
    }

    public function published(\DateTimeImmutable $now, ?string $categoryId, int $offset, int $limit): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative(
            self::SELECT.' WHERE a.published_at <= :now AND (CAST(:cat AS uuid) IS NULL OR a.category_id = CAST(:cat AS uuid))
             ORDER BY a.featured DESC, a.published_at DESC, a.id LIMIT :limit OFFSET :offset',
            ['now' => $now->format(\DATE_ATOM), 'cat' => $categoryId, 'limit' => $limit, 'offset' => $offset],
        ));
    }

    public function countPublished(\DateTimeImmutable $now, ?string $categoryId): int
    {
        return DbalValue::int($this->connection->fetchOne(
            'SELECT count(*) FROM content.blog_article WHERE published_at <= :now AND (CAST(:cat AS uuid) IS NULL OR category_id = CAST(:cat AS uuid))',
            ['now' => $now->format(\DATE_ATOM), 'cat' => $categoryId],
        ));
    }

    public function related(BlogArticle $article, \DateTimeImmutable $now, int $limit): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative(
            self::SELECT.' WHERE a.published_at <= :now AND a.id <> :id
             ORDER BY (a.category_id IS NOT DISTINCT FROM CAST(:cat AS uuid)) DESC, a.published_at DESC LIMIT :limit',
            ['now' => $now->format(\DATE_ATOM), 'id' => $article->id, 'cat' => $article->category?->id, 'limit' => $limit],
        ));
    }

    public function create(string $id, string $slug, string $title, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->insert('content.blog_article', ['id' => $id, 'slug' => $slug, 'title' => $title, 'created_at' => $at->format(\DATE_ATOM), 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId]);
    }

    public function save(string $id, array $fields, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE content.blog_article SET slug = :slug, title = :title, short_title = :short_title, excerpt = :excerpt, category_id = :category_id,
                author_name = :author_name, author_media_id = :author_media_id, cover_media_id = :cover_media_id, content_html = :content_html,
                job_ids = CAST(:job_ids AS uuid[]), featured = :featured, meta_title = :meta_title, meta_description = :meta_description,
                updated_at = :at, updated_by = :by
             WHERE id = :id',
            [...$fields, 'job_ids' => '{'.implode(',', $fields['job_ids']).'}', 'featured' => $fields['featured'] ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM), 'by' => $adminId, 'id' => $id],
        );
    }

    public function setPublishedAt(string $id, ?\DateTimeImmutable $publishedAt, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.blog_article', ['published_at' => $publishedAt?->format(\DATE_ATOM), 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId], ['id' => $id]);
    }

    public function delete(string $id): void
    {
        $this->connection->delete('content.blog_article', ['id' => $id]);
    }

    public function usingMedia(string $mediaId): array
    {
        if (1 !== preg_match(self::UUID, $mediaId)) {
            return [];
        }

        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            'SELECT title FROM content.blog_article WHERE cover_media_id = :m OR author_media_id = :m ORDER BY title',
            ['m' => $mediaId],
        ));
    }

    /** @param array<string, mixed> $r */
    private static function categoryView(array $r): BlogCategory
    {
        return new BlogCategory(DbalValue::string($r['id']), DbalValue::string($r['slug']), DbalValue::string($r['name']), DbalValue::int($r['position']));
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): BlogArticle
    {
        $jobs = DbalValue::json($r['job_ids_json']);
        $category = null === $r['category_id'] ? null : new BlogCategory(DbalValue::string($r['category_id']), DbalValue::string($r['category_slug']), DbalValue::string($r['category_name']), DbalValue::int($r['category_position']));

        return new BlogArticle(
            DbalValue::string($r['id']),
            DbalValue::string($r['slug']),
            DbalValue::string($r['title']),
            DbalValue::nullableString($r['short_title']),
            DbalValue::nullableString($r['excerpt']),
            $category,
            DbalValue::nullableString($r['author_name']),
            DbalValue::nullableString($r['author_media_id']),
            DbalValue::nullableString($r['cover_media_id']),
            DbalValue::string($r['content_html']),
            array_values(array_filter(\is_array($jobs) ? $jobs : [], 'is_string')),
            true === $r['featured'],
            DbalValue::nullableString($r['meta_title']),
            DbalValue::nullableString($r['meta_description']),
            null === $r['published_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['published_at'])),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
        );
    }
}
