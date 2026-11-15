<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Media\MediaLibrary;
use App\Content\Application\Media\MediaView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalMediaLibrary implements MediaLibrary
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function add(string $id, string $fileId, string $title, string $alt, string $focal, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->insert('content.media', ['id' => $id, 'file_id' => $fileId, 'title' => $title, 'alt' => $alt, 'focal' => $focal, 'created_by' => $adminId, 'created_at' => $at->format(\DATE_ATOM), 'updated_at' => $at->format(\DATE_ATOM)]);
    }

    public function find(string $id): ?MediaView
    {
        return 1 === preg_match(self::UUID, $id) ? $this->one('id', $id) : null;
    }

    public function findByFile(string $fileId): ?MediaView
    {
        return 1 === preg_match(self::UUID, $fileId) ? $this->one('file_id', $fileId) : null;
    }

    public function all(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative('SELECT * FROM content.media ORDER BY created_at DESC, id'));
    }

    public function update(string $id, string $title, string $alt, string $focal, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.media', ['title' => $title, 'alt' => $alt, 'focal' => $focal, 'updated_at' => $at->format(\DATE_ATOM)], ['id' => $id]);
    }

    public function markReady(string $id, int $width, int $height, array $variants, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.media', ['width' => $width, 'height' => $height, 'variants' => json_encode($variants, \JSON_THROW_ON_ERROR), 'status' => 'ready', 'updated_at' => $at->format(\DATE_ATOM)], ['id' => $id]);
    }

    public function markFailed(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.media', ['status' => 'failed', 'updated_at' => $at->format(\DATE_ATOM)], ['id' => $id]);
    }

    public function delete(string $id): void
    {
        $this->connection->delete('content.media', ['id' => $id]);
    }

    private function one(string $column, string $value): ?MediaView
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM content.media WHERE '.$column.' = :v', ['v' => $value]);

        return false === $row ? null : self::view($row);
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): MediaView
    {
        $decoded = json_decode(DbalValue::string($r['variants']), true);
        $variants = [];
        foreach (\is_array($decoded) ? $decoded : [] as $format => $byWidth) {
            foreach (\is_array($byWidth) ? $byWidth : [] as $width => $file) {
                if (\is_string($file)) {
                    $variants[(string) $format][(int) $width] = $file;
                }
            }
        }

        return new MediaView(
            DbalValue::string($r['id']),
            DbalValue::string($r['file_id']),
            DbalValue::string($r['title']),
            DbalValue::string($r['alt']),
            DbalValue::string($r['focal']),
            null === $r['width'] ? null : DbalValue::int($r['width']),
            null === $r['height'] ? null : DbalValue::int($r['height']),
            $variants,
            DbalValue::string($r['status']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        );
    }
}
