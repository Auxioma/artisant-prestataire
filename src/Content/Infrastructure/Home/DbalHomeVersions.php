<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Home;

use App\Content\Application\Home\HomeVersion;
use App\Content\Application\Port\HomeVersions;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type Block from HomeVersion
 */
final readonly class DbalHomeVersions implements HomeVersions
{
    private const string SELECT = 'SELECT id, number, blocks, author_id, updated_at, publish_at, published_by FROM content.home_version';

    public function __construct(private Connection $connection)
    {
    }

    public function draft(): ?HomeVersion
    {
        return $this->one(self::SELECT.' WHERE publish_at IS NULL', []);
    }

    public function live(\DateTimeImmutable $now): ?HomeVersion
    {
        return $this->one(self::SELECT.' WHERE publish_at <= :now ORDER BY publish_at DESC, number DESC LIMIT 1', ['now' => $now->format(\DATE_ATOM)]);
    }

    public function scheduled(\DateTimeImmutable $now): ?HomeVersion
    {
        return $this->one(self::SELECT.' WHERE publish_at > :now ORDER BY publish_at LIMIT 1', ['now' => $now->format(\DATE_ATOM)]);
    }

    public function find(string $id): ?HomeVersion
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }

        return $this->one(self::SELECT.' WHERE id = :id', ['id' => $id]);
    }

    public function history(int $limit): array
    {
        return array_map($this->version(...), $this->connection->fetchAllAssociative(
            self::SELECT.' WHERE publish_at IS NOT NULL ORDER BY publish_at DESC, number DESC LIMIT :limit',
            ['limit' => $limit],
        ));
    }

    public function saveDraft(HomeVersion $draft): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.home_version (id, number, blocks, author_id, created_at, updated_at)
             VALUES (:id, :number, CAST(:blocks AS jsonb), :author, :at, :at)
             ON CONFLICT (id) DO UPDATE SET blocks = EXCLUDED.blocks, author_id = EXCLUDED.author_id, updated_at = EXCLUDED.updated_at
             WHERE content.home_version.publish_at IS NULL',
            ['id' => $draft->id, 'number' => $draft->number, 'blocks' => json_encode($draft->blocks, \JSON_THROW_ON_ERROR), 'author' => $draft->authorId, 'at' => $draft->updatedAt->format(\DATE_ATOM)],
        );
    }

    public function publish(string $id, \DateTimeImmutable $publishAt, string $adminId): void
    {
        $this->connection->executeStatement(
            'UPDATE content.home_version SET publish_at = :at, published_by = :admin WHERE id = :id AND publish_at IS NULL',
            ['id' => $id, 'at' => $publishAt->format(\DATE_ATOM), 'admin' => $adminId],
        );
    }

    public function delete(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM content.home_version WHERE id = :id AND publish_at IS NULL', ['id' => $id]);
    }

    public function nextNumber(): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT coalesce(max(number), 0) + 1 FROM content.home_version'));
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params): ?HomeVersion
    {
        $row = $this->connection->fetchAssociative($sql, $params);

        return false === $row ? null : $this->version($row);
    }

    /** @param array<string, mixed> $row */
    private function version(array $row): HomeVersion
    {
        $decoded = DbalValue::json($row['blocks']);
        $blocks = [];
        foreach (\is_array($decoded) ? $decoded : [] as $block) {
            if (\is_array($block) && \is_string($block['type'] ?? null)) {
                $settings = \is_array($block['settings'] ?? null) ? $block['settings'] : [];
                /** @var array<string, mixed> $settings */
                $blocks[] = ['type' => $block['type'], 'enabled' => true === ($block['enabled'] ?? false), 'settings' => $settings];
            }
        }

        return new HomeVersion(
            DbalValue::string($row['id']),
            DbalValue::int($row['number']),
            $blocks,
            DbalValue::string($row['author_id']),
            new \DateTimeImmutable(DbalValue::string($row['updated_at'])),
            null === $row['publish_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['publish_at'])),
            DbalValue::nullableString($row['published_by']),
        );
    }
}
