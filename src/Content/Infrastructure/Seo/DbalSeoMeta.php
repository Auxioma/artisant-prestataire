<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Seo;

use App\Content\Application\Port\NotFoundLog;
use App\Content\Application\Port\SeoMeta;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSeoMeta implements SeoMeta, NotFoundLog
{
    private const int PATH_MAX = 500;
    private const int REFERER_MAX = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function templates(): array
    {
        $templates = [];
        foreach ($this->connection->fetchAllAssociative('SELECT page_type, title, description FROM content.seo_template') as $r) {
            $templates[DbalValue::string($r['page_type'])] = ['title' => DbalValue::nullableString($r['title']), 'description' => DbalValue::nullableString($r['description'])];
        }

        return $templates;
    }

    public function saveTemplate(string $pageType, ?string $title, ?string $description, string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.seo_template (page_type, title, description, updated_at, updated_by) VALUES (:type, :title, :description, :at, :admin)
             ON CONFLICT (page_type) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            ['type' => $pageType, 'title' => $title, 'description' => $description, 'at' => $at->format(\DATE_ATOM), 'admin' => $adminId],
        );
    }

    public function overrides(): array
    {
        return array_map(self::row(...), $this->connection->fetchAllAssociative('SELECT path, title, description, noindex FROM content.seo_override ORDER BY path'));
    }

    public function override(string $path): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT path, title, description, noindex FROM content.seo_override WHERE path = :path', ['path' => $path]);

        return false === $row ? null : self::row($row);
    }

    public function saveOverride(string $path, ?string $title, ?string $description, bool $noindex, string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.seo_override (path, title, description, noindex, updated_at, updated_by) VALUES (:path, :title, :description, :noindex, :at, :admin)
             ON CONFLICT (path) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, noindex = EXCLUDED.noindex, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            ['path' => $path, 'title' => $title, 'description' => $description, 'noindex' => $noindex ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM), 'admin' => $adminId],
        );
    }

    public function deleteOverride(string $path): void
    {
        $this->connection->executeStatement('DELETE FROM content.seo_override WHERE path = :path', ['path' => $path]);
    }

    public function record(string $path, ?string $referer, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.not_found (path, last_referer, first_seen_at, last_seen_at) VALUES (:path, :referer, :at, :at)
             ON CONFLICT (path) DO UPDATE SET hits = content.not_found.hits + 1, last_referer = coalesce(EXCLUDED.last_referer, content.not_found.last_referer), last_seen_at = EXCLUDED.last_seen_at',
            ['path' => mb_substr($path, 0, self::PATH_MAX), 'referer' => null === $referer ? null : mb_substr($referer, 0, self::REFERER_MAX), 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function top(int $limit): array
    {
        return array_map(static fn (array $r): array => [
            'path' => DbalValue::string($r['path']),
            'hits' => DbalValue::int($r['hits']),
            'last_referer' => DbalValue::nullableString($r['last_referer']),
            'last_seen_at' => DbalValue::string($r['last_seen_at']),
        ], $this->connection->fetchAllAssociative('SELECT * FROM content.not_found ORDER BY hits DESC, last_seen_at DESC LIMIT :limit', ['limit' => $limit]));
    }

    public function dismiss(string $path): void
    {
        $this->connection->executeStatement('DELETE FROM content.not_found WHERE path = :path', ['path' => $path]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{path: string, title: ?string, description: ?string, noindex: bool}
     */
    private static function row(array $r): array
    {
        return ['path' => DbalValue::string($r['path']), 'title' => DbalValue::nullableString($r['title']), 'description' => DbalValue::nullableString($r['description']), 'noindex' => true === $r['noindex']];
    }
}
