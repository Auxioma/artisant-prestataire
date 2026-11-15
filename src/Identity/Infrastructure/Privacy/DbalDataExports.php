<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Identity\Application\Privacy\DataExports;
use App\Identity\Application\Privacy\DataExportView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalDataExports implements DataExports
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function create(string $id, string $userId, \DateTimeImmutable $at): void
    {
        $this->connection->insert('identity.data_export', ['id' => $id, 'user_id' => $userId, 'status' => 'requested', 'requested_at' => $at->format(\DATE_ATOM)]);
    }

    public function latest(string $userId): ?DataExportView
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM identity.data_export WHERE user_id = :u ORDER BY requested_at DESC, id DESC LIMIT 1', ['u' => $userId]);

        return false === $row ? null : self::view($row);
    }

    public function find(string $id): ?DataExportView
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM identity.data_export WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function markReady(string $id, string $fileId, \DateTimeImmutable $at, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement(
            "UPDATE identity.data_export SET status = 'ready', file_id = :f, ready_at = :at, expires_at = :exp WHERE id = :id",
            ['f' => $fileId, 'at' => $at->format(\DATE_ATOM), 'exp' => $expiresAt->format(\DATE_ATOM), 'id' => $id],
        );
    }

    public function markFailed(string $id): void
    {
        $this->connection->executeStatement("UPDATE identity.data_export SET status = 'failed' WHERE id = :id AND status = 'requested'", ['id' => $id]);
    }

    public function expiredBefore(\DateTimeImmutable $now): array
    {
        return array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'fileId' => DbalValue::nullableString($r['file_id'])], $this->connection->fetchAllAssociative(
            "SELECT id, file_id FROM identity.data_export WHERE status = 'ready' AND expires_at <= :now",
            ['now' => $now->format(\DATE_ATOM)],
        ));
    }

    public function markExpired(string $id): void
    {
        $this->connection->executeStatement("UPDATE identity.data_export SET status = 'expired', file_id = NULL WHERE id = :id", ['id' => $id]);
    }

    public function forget(string $userId): array
    {
        $files = array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT file_id FROM identity.data_export WHERE user_id = :u AND file_id IS NOT NULL', ['u' => $userId]));
        $this->connection->executeStatement('DELETE FROM identity.data_export WHERE user_id = :u', ['u' => $userId]);

        return $files;
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): DataExportView
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return new DataExportView(
            DbalValue::string($r['id']),
            DbalValue::string($r['user_id']),
            DbalValue::string($r['status']),
            DbalValue::nullableString($r['file_id']),
            new \DateTimeImmutable(DbalValue::string($r['requested_at'])),
            $date($r['ready_at']),
            $date($r['expires_at']),
        );
    }
}
