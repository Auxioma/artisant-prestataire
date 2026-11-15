<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Persistence;

use App\Moderation\Application\Report\Reports;
use App\Moderation\Application\Report\ReportView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalReports implements Reports
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SELECT = <<<'SQL'
        SELECT r.*, CASE WHEN u.id IS NULL THEN NULL ELSE u.first_name || ' ' || u.last_name END AS reporter_name,
               (SELECT count(*) FROM moderation.report o WHERE o.subject_type = r.subject_type AND o.subject_id = r.subject_id AND o.status = 'open') AS similar
        FROM moderation.report r LEFT JOIN identity.user_account u ON u.id = r.reporter_id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function add(ReportView $report): void
    {
        $this->connection->insert('moderation.report', [
            'id' => $report->id,
            'subject_type' => $report->type,
            'subject_id' => $report->subjectId,
            'subject_title' => $report->title,
            'subject_excerpt' => $report->excerpt,
            'subject_owner_id' => $report->ownerId,
            'reporter_id' => $report->reporterId,
            'reason' => $report->reason,
            'details' => $report->details,
            'status' => $report->status,
            'created_at' => $report->createdAt->format(\DATE_ATOM),
        ]);
    }

    public function hasOpen(string $reporterId, string $type, string $subjectId): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM moderation.report WHERE reporter_id = :r AND subject_type = :t AND subject_id = :s AND status = 'open'",
            ['r' => $reporterId, 't' => $type, 's' => $subjectId],
        );
    }

    public function open(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative(self::SELECT." WHERE r.status = 'open' ORDER BY r.created_at, r.id"));
    }

    public function find(string $id): ?ReportView
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE r.id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function decide(string $id, string $status, string $adminId, ?string $note, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            "UPDATE moderation.report SET status = :s, decided_by = :a, decided_at = :at, decision_note = :n WHERE id = :id AND status = 'open'",
            ['s' => $status, 'a' => $adminId, 'at' => $at->format(\DATE_ATOM), 'n' => $note, 'id' => $id],
        );
    }

    public function reporterContact(string $id): array
    {
        return array_map(static fn (array $r): array => ['email' => DbalValue::string($r['email']), 'firstName' => DbalValue::string($r['first_name']), 'userId' => DbalValue::string($r['id'])], $this->connection->fetchAllAssociative(
            "SELECT u.id, u.email, u.first_name FROM moderation.report r JOIN identity.user_account u ON u.id = r.reporter_id AND u.status NOT IN ('anonymized', 'deletion_requested') WHERE r.id = :id",
            ['id' => $id],
        ));
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): ReportView
    {
        return new ReportView(
            DbalValue::string($r['id']),
            DbalValue::string($r['subject_type']),
            DbalValue::string($r['subject_id']),
            DbalValue::string($r['subject_title']),
            DbalValue::string($r['subject_excerpt']),
            DbalValue::nullableString($r['subject_owner_id']),
            DbalValue::nullableString($r['reporter_id']),
            DbalValue::string($r['reason']),
            DbalValue::nullableString($r['details']),
            DbalValue::string($r['status']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            DbalValue::nullableString($r['reporter_name']),
            DbalValue::int($r['similar']),
        );
    }
}
