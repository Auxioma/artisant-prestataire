<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Maintenance;

use App\QuoteRequest\Application\Maintenance\DraftReminder;
use App\QuoteRequest\Application\Maintenance\ExpiredDraft;
use App\QuoteRequest\Application\Maintenance\ExpiryNotice;
use App\QuoteRequest\Application\Port\RequestMaintenanceStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalRequestMaintenanceStore implements RequestMaintenanceStore
{
    private const string NOTICE = <<<'SQL'
        SELECT r.id, r.client_id, r.number, j.name AS job_name, coalesce(c.name, r.postal_code) AS city_name, r.postal_code, r.expires_at, r.received_quotes_count
        FROM request.quote_request r
        JOIN catalog.job j ON j.id = r.job_id
        LEFT JOIN catalog.commune c ON c.insee = r.city_insee
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT id FROM request.quote_request WHERE status IN ('open', 'full') AND expires_at <= :now ORDER BY expires_at LIMIT ".max(1, $limit),
            ['now' => $now->format(\DATE_ATOM)],
        ));
    }

    public function expiringSoon(\DateTimeImmutable $now, \DateTimeImmutable $until, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::NOTICE." WHERE r.status IN ('open', 'full') AND r.expires_at > :now AND r.expires_at <= :until
               AND NOT EXISTS (SELECT 1 FROM request.reminder_sent s WHERE s.kind = :kind AND s.subject_id = r.id AND s.step = to_char(r.expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI'))
             ORDER BY r.expires_at LIMIT ".max(1, $limit),
            ['now' => $now->format(\DATE_ATOM), 'until' => $until->format(\DATE_ATOM), 'kind' => self::EXPIRY_WARNING],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function notice(string $requestId): ?ExpiryNotice
    {
        $row = $this->connection->fetchAssociative(self::NOTICE.' WHERE r.id = :id', ['id' => $requestId]);

        return false === $row ? null : self::hydrate($row);
    }

    public function remindableDrafts(\DateTimeImmutable $idleSince, \DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT d.id, d.owner_user_id, j.name AS job_name
             FROM request.request_draft d
             LEFT JOIN catalog.job j ON j.id::text = d.payload->>'job_id'
             WHERE d.owner_user_id IS NOT NULL AND d.submit_requested_at IS NULL AND d.updated_at <= :idle AND d.expires_at > :now
               AND NOT EXISTS (SELECT 1 FROM request.reminder_sent s WHERE s.kind = :kind AND s.subject_id = d.id)
             ORDER BY d.updated_at LIMIT ".max(1, $limit),
            ['idle' => $idleSince->format(\DATE_ATOM), 'now' => $now->format(\DATE_ATOM), 'kind' => self::DRAFT_REMINDER],
        );

        return array_map(static fn (array $r): DraftReminder => new DraftReminder(DbalValue::string($r['id']), DbalValue::string($r['owner_user_id']), DbalValue::nullableString($r['job_name'])), $rows);
    }

    public function expiredDrafts(\DateTimeImmutable $now, int $limit): array
    {
        $drafts = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM request.request_draft WHERE expires_at <= :now ORDER BY expires_at LIMIT '.max(1, $limit), ['now' => $now->format(\DATE_ATOM)]) as $id) {
            $files = $this->connection->fetchFirstColumn('SELECT file_id FROM request.draft_attachment WHERE draft_id = :id', ['id' => $id]);
            $drafts[] = new ExpiredDraft(DbalValue::string($id), array_map(DbalValue::string(...), $files));
        }

        return $drafts;
    }

    public function markSent(string $kind, string $subjectId, string $step, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO request.reminder_sent (kind, subject_id, step, sent_at) VALUES (:k, :s, :step, :at) ON CONFLICT DO NOTHING',
            ['k' => $kind, 's' => $subjectId, 'step' => $step, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ExpiryNotice
    {
        return new ExpiryNotice(
            DbalValue::string($row['id']),
            DbalValue::string($row['client_id']),
            DbalValue::string($row['number']),
            DbalValue::string($row['job_name']),
            DbalValue::string($row['city_name']).' ('.DbalValue::string($row['postal_code']).')',
            new \DateTimeImmutable(DbalValue::string($row['expires_at'])),
            DbalValue::int($row['received_quotes_count']),
        );
    }
}
