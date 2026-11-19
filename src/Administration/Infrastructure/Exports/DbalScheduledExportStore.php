<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Exports;

use App\Administration\Application\Exports\ScheduledExport;
use App\Administration\Application\Exports\ScheduledExportStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalScheduledExportStore implements ScheduledExportStore
{
    private const string COLUMNS = 'id, kind, period_label, file_id, row_count, created_at, expires_at';

    public function __construct(private Connection $connection)
    {
    }

    public function add(ScheduledExport $export): void
    {
        $this->connection->insert('admin.scheduled_export', [
            'id' => $export->id, 'kind' => $export->kind, 'period_label' => $export->periodLabel, 'file_id' => $export->fileId,
            'row_count' => $export->rows, 'created_at' => $export->createdAt->format(\DATE_ATOM), 'expires_at' => $export->expiresAt->format(\DATE_ATOM),
        ]);
    }

    public function find(string $id): ?ScheduledExport
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM admin.scheduled_export WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::map($row);
    }

    public function latest(int $limit): array
    {
        return array_map(self::map(...), $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM admin.scheduled_export ORDER BY created_at DESC LIMIT :l', ['l' => $limit]));
    }

    public function expired(\DateTimeImmutable $now): array
    {
        return array_map(self::map(...), $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM admin.scheduled_export WHERE expires_at <= :now', ['now' => $now->format(\DATE_ATOM)]));
    }

    public function delete(string $id): void
    {
        $this->connection->delete('admin.scheduled_export', ['id' => $id]);
    }

    /** @param array<string, mixed> $r */
    private static function map(array $r): ScheduledExport
    {
        return new ScheduledExport(
            DbalValue::string($r['id']),
            DbalValue::string($r['kind']),
            DbalValue::string($r['period_label']),
            DbalValue::string($r['file_id']),
            DbalValue::int($r['row_count']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            new \DateTimeImmutable(DbalValue::string($r['expires_at'])),
        );
    }
}
