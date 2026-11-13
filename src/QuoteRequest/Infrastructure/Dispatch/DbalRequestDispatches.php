<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Dispatch;

use App\QuoteRequest\Application\Dispatch\AlertFrequency;
use App\QuoteRequest\Application\Dispatch\DispatchReason;
use App\QuoteRequest\Application\Dispatch\DispatchSummary;
use App\QuoteRequest\Application\Dispatch\PendingDigest;
use App\QuoteRequest\Application\Port\RequestDispatches;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalRequestDispatches implements RequestDispatches
{
    /** Métier et commune lus dans le référentiel Catalog (lecture seule). */
    private const string SUMMARY = <<<'SQL'
        SELECT r.id, r.number, j.name AS job_name, coalesce(c.name, r.postal_code) AS city_name, r.postal_code, r.timing, r.budget_range, r.title
        FROM request.quote_request r
        JOIN catalog.job j ON j.id = r.job_id
        LEFT JOIN catalog.commune c ON c.insee = r.city_insee
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function record(string $requestId, string $artisanId, DispatchReason $reason, \DateTimeImmutable $at, bool $notified): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO request.request_dispatch (request_id, artisan_id, reason, dispatched_at, notified_at) VALUES (:r, :a, :reason, :at, :notified) ON CONFLICT DO NOTHING',
            ['r' => $requestId, 'a' => $artisanId, 'reason' => $reason->value, 'at' => $at->format(\DATE_ATOM), 'notified' => $notified ? $at->format(\DATE_ATOM) : null],
        );
    }

    public function countFor(string $requestId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_dispatch WHERE request_id = :r', ['r' => $requestId]));
    }

    public function summary(string $requestId): ?DispatchSummary
    {
        $row = $this->connection->fetchAssociative(self::SUMMARY.' WHERE r.id = :id', ['id' => $requestId]);

        return false === $row ? null : self::summaryOf($row);
    }

    public function pendingDigests(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.artisan_id, p.user_id, coalesce(rp.alert_frequency, 'immediate') AS frequency, s.*
            FROM request.request_dispatch d
            JOIN artisan.artisan_profile p ON p.id = d.artisan_id
            LEFT JOIN artisan.request_preference rp ON rp.artisan_id = d.artisan_id
            JOIN LATERAL (
            SQL.self::SUMMARY.<<<'SQL'
                WHERE r.id = d.request_id AND r.status = 'open'
            ) s ON true
            WHERE d.notified_at IS NULL AND rp.paused_at IS NULL
            ORDER BY d.artisan_id, d.dispatched_at DESC
            SQL);

        /** @var array<string, array{user: string, frequency: AlertFrequency, requests: non-empty-list<DispatchSummary>}> $byArtisan */
        $byArtisan = [];
        foreach ($rows as $row) {
            $artisan = DbalValue::string($row['artisan_id']);
            $summary = self::summaryOf($row);
            if (isset($byArtisan[$artisan])) {
                $byArtisan[$artisan]['requests'][] = $summary;
                continue;
            }
            $byArtisan[$artisan] = ['user' => DbalValue::string($row['user_id']), 'frequency' => AlertFrequency::tryFrom(DbalValue::string($row['frequency'])) ?? AlertFrequency::Immediate, 'requests' => [$summary]];
        }

        $digests = [];
        foreach ($byArtisan as $artisan => $digest) {
            $digests[] = new PendingDigest($artisan, $digest['user'], $digest['frequency'], $digest['requests']);
        }

        return $digests;
    }

    public function markNotified(string $artisanId, array $requestIds, \DateTimeImmutable $at): void
    {
        if ([] === $requestIds) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE request.request_dispatch SET notified_at = :at WHERE artisan_id = :a AND request_id IN (:ids) AND notified_at IS NULL',
            ['at' => $at->format(\DATE_ATOM), 'a' => $artisanId, 'ids' => $requestIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /** @param array<string, mixed> $row */
    private static function summaryOf(array $row): DispatchSummary
    {
        $budget = BudgetRange::tryFrom(DbalValue::nullableString($row['budget_range'] ?? null) ?? '');
        $timing = DbalValue::string($row['timing']);
        $title = trim(DbalValue::nullableString($row['title'] ?? null) ?? '');

        return new DispatchSummary(
            DbalValue::string($row['id']),
            DbalValue::string($row['number']),
            DbalValue::string($row['job_name']),
            DbalValue::string($row['city_name']).' ('.DbalValue::string($row['postal_code']).')',
            Timing::tryFrom($timing)?->label() ?? $timing,
            null === $budget || BudgetRange::Unknown === $budget ? null : $budget->label(),
            '' === $title ? null : $title,
        );
    }
}
