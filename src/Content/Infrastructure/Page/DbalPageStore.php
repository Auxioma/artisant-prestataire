<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Page;

use App\Content\Application\Page\PageStore;
use App\Content\Application\Page\PageView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalPageStore implements PageStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function create(string $id, string $slug, string $title, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->insert('content.page', ['id' => $id, 'slug' => $slug, 'title' => $title, 'created_at' => $at->format(\DATE_ATOM), 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId]);
    }

    public function find(string $id): ?PageView
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM content.page WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function findBySlug(string $slug): ?PageView
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM content.page WHERE slug = :s', ['s' => $slug]);

        return false === $row ? null : self::view($row);
    }

    public function all(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative('SELECT * FROM content.page ORDER BY title'));
    }

    public function slugTaken(string $slug, ?string $exceptId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM content.page WHERE slug = :s AND id IS DISTINCT FROM :id', ['s' => $slug, 'id' => $exceptId]);
    }

    public function saveMeta(string $id, string $slug, string $title, ?string $metaTitle, ?string $metaDescription, bool $noindex, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.page', ['slug' => $slug, 'title' => $title, 'meta_title' => $metaTitle, 'meta_description' => $metaDescription, 'noindex' => $noindex ? 'true' : 'false', 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId], ['id' => $id]);
    }

    public function saveDraft(string $id, array $blocks, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.page', ['draft' => json_encode($blocks, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId], ['id' => $id]);
    }

    public function publish(string $id, \DateTimeImmutable $at, bool $scheduled, ?string $adminId, \DateTimeImmutable $now): void
    {
        // Une version programmée dont l'heure est passée devient d'abord la version en ligne.
        $this->connection->executeStatement('UPDATE content.page SET live = pending, live_at = pending_at, pending = NULL, pending_at = NULL WHERE id = :id AND pending_at <= :now', ['id' => $id, 'now' => $now->format(\DATE_ATOM)]);
        $this->connection->executeStatement(
            $scheduled
                ? 'UPDATE content.page SET pending = draft, pending_at = :at, updated_at = :now, updated_by = :admin WHERE id = :id'
                : 'UPDATE content.page SET live = draft, live_at = :at, pending = NULL, pending_at = NULL, updated_at = :now, updated_by = :admin WHERE id = :id',
            ['id' => $id, 'at' => $at->format(\DATE_ATOM), 'now' => $now->format(\DATE_ATOM), 'admin' => $adminId],
        );
    }

    public function unpublish(string $id, ?string $adminId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE content.page SET live = NULL, live_at = NULL, pending = NULL, pending_at = NULL, updated_at = :now, updated_by = :admin WHERE id = :id', ['id' => $id, 'now' => $now->format(\DATE_ATOM), 'admin' => $adminId]);
    }

    public function delete(string $id): void
    {
        $this->connection->delete('content.page', ['id' => $id]);
    }

    public function usingMedia(string $mediaId): array
    {
        if (1 !== preg_match(self::UUID, $mediaId)) {
            return [];
        }

        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT title FROM content.page WHERE strpos(draft::text || coalesce(live::text, '') || coalesce(pending::text, ''), :m) > 0 ORDER BY title",
            ['m' => '"'.$mediaId.'"'],
        ));
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): PageView
    {
        $blocks = static function (mixed $json): ?array {
            if (null === $json) {
                return null;
            }
            $decoded = json_decode(DbalValue::string($json), true);

            return array_values(array_filter(\is_array($decoded) ? $decoded : [], static fn (mixed $b): bool => \is_array($b) && \is_string($b['id'] ?? null) && \is_string($b['type'] ?? null) && \is_array($b['data'] ?? null)));
        };
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        /** @var list<array{id: string, type: string, data: array<string, mixed>}> $draft */
        $draft = $blocks($r['draft']) ?? [];
        /** @var list<array{id: string, type: string, data: array<string, mixed>}>|null $live */
        $live = $blocks($r['live']);
        /** @var list<array{id: string, type: string, data: array<string, mixed>}>|null $pending */
        $pending = $blocks($r['pending']);

        return new PageView(
            DbalValue::string($r['id']),
            DbalValue::string($r['slug']),
            DbalValue::string($r['title']),
            DbalValue::nullableString($r['meta_title']),
            DbalValue::nullableString($r['meta_description']),
            true === $r['noindex'],
            $draft,
            $live,
            $date($r['live_at']),
            $pending,
            $date($r['pending_at']),
            new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
        );
    }
}
