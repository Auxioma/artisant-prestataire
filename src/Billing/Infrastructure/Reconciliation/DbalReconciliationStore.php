<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Reconciliation;

use App\Billing\Application\Reconciliation\ReconciliationIssue;
use App\Billing\Application\Reconciliation\ReconciliationRun;
use App\Billing\Application\Reconciliation\ReconciliationStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalReconciliationStore implements ReconciliationStore
{
    /** Microsecondes : deux passages rapprochés ne doivent pas se confondre (résolution automatique, dernier passage). */
    private const string AT = 'Y-m-d\\TH:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function localSubscriptions(): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT stripe_subscription_id, status, current_period_end FROM billing.subscription WHERE stripe_subscription_id IS NOT NULL') as $r) {
            $rows[DbalValue::string($r['stripe_subscription_id'])] = ['status' => DbalValue::string($r['status']), 'period_end' => DbalValue::nullableString($r['current_period_end'])];
        }

        return $rows;
    }

    public function knownCustomers(): array
    {
        return array_values(array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT stripe_customer_id FROM billing.credit_account WHERE stripe_customer_id IS NOT NULL')));
    }

    public function localInvoices(\DateTimeImmutable $since): array
    {
        $rows = [];
        // Fenêtre élargie d'un jour : une facture locale datée juste avant la borne reste reconnue.
        foreach ($this->connection->fetchAllAssociative(
            "SELECT i.stripe_invoice_id, i.status, i.amount_paid_cents, EXISTS (SELECT 1 FROM billing.subscription_period p WHERE p.stripe_invoice_id = i.stripe_invoice_id) AS granted FROM billing.invoice i WHERE i.issued_at >= CAST(:since AS timestamptz) - interval '1 day'",
            ['since' => $since->format(\DATE_ATOM)],
        ) as $r) {
            $rows[DbalValue::string($r['stripe_invoice_id'])] = ['status' => DbalValue::string($r['status']), 'amount_paid' => DbalValue::int($r['amount_paid_cents']), 'period_granted' => true === $r['granted']];
        }

        return $rows;
    }

    public function ledgerMismatches(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT b.artisan_id, x.bucket, x.balance, coalesce(l.total, 0) AS ledger
                FROM billing.credit_balance b
                CROSS JOIN LATERAL (VALUES ('free', b.free), ('plan', b.plan), ('extra', b.extra)) AS x(bucket, balance)
                LEFT JOIN (SELECT artisan_id, bucket, sum(amount) AS total FROM billing.credit_ledger_entry GROUP BY artisan_id, bucket) l
                       ON l.artisan_id = b.artisan_id AND l.bucket = x.bucket
                WHERE x.balance <> coalesce(l.total, 0)
                ORDER BY b.artisan_id, x.bucket
                SQL,
        );

        return array_map(static fn (array $r): array => ['artisan_id' => DbalValue::string($r['artisan_id']), 'bucket' => DbalValue::string($r['bucket']), 'balance' => DbalValue::int($r['balance']), 'ledger' => DbalValue::int($r['ledger'])], $rows);
    }

    public function stuckEvents(\DateTimeImmutable $before): array
    {
        return array_values(array_map(DbalValue::string(...), $this->connection->fetchFirstColumn("SELECT event_id FROM billing.processed_stripe_event WHERE status IN ('received', 'failed') AND received_at < :before ORDER BY received_at", ['before' => $before->format(\DATE_ATOM)])));
    }

    public function raise(string $id, string $kind, string $reference, string $details, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.reconciliation_issue (id, kind, reference, details, detected_at, last_seen_at) VALUES (:id, :kind, :ref, :details, :at, :at)
                ON CONFLICT (kind, reference) WHERE resolved_at IS NULL DO UPDATE SET details = excluded.details, last_seen_at = excluded.last_seen_at
                SQL,
            ['id' => $id, 'kind' => $kind, 'ref' => mb_substr($reference, 0, 255), 'details' => $details, 'at' => $at->format(self::AT)],
        );
    }

    public function resolveStale(array $kinds, \DateTimeImmutable $runStartedAt, \DateTimeImmutable $at): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE billing.reconciliation_issue SET resolved_at = :at, resolution = 'auto' WHERE resolved_at IS NULL AND kind IN (:kinds) AND last_seen_at < :started",
            ['at' => $at->format(self::AT), 'kinds' => $kinds, 'started' => $runStartedAt->format(self::AT)],
            ['kinds' => ArrayParameterType::STRING],
        );
    }

    public function recordRun(string $id, \DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt, bool $stripeChecked, int $checked, int $issues): void
    {
        $this->connection->insert('billing.reconciliation_run', ['id' => $id, 'started_at' => $startedAt->format(self::AT), 'finished_at' => $finishedAt->format(self::AT), 'stripe_checked' => $stripeChecked ? 'true' : 'false', 'checked' => $checked, 'issues' => $issues]);
    }

    public function lastRun(): ?ReconciliationRun
    {
        $r = $this->connection->fetchAssociative('SELECT * FROM billing.reconciliation_run ORDER BY finished_at DESC LIMIT 1');

        return false === $r ? null : new ReconciliationRun(new \DateTimeImmutable(DbalValue::string($r['finished_at'])), true === $r['stripe_checked'], DbalValue::int($r['checked']), DbalValue::int($r['issues']));
    }

    public function openIssues(): array
    {
        return array_map(static fn (array $r): ReconciliationIssue => new ReconciliationIssue(
            DbalValue::string($r['id']),
            DbalValue::string($r['kind']),
            DbalValue::string($r['reference']),
            DbalValue::string($r['details']),
            new \DateTimeImmutable(DbalValue::string($r['detected_at'])),
            new \DateTimeImmutable(DbalValue::string($r['last_seen_at'])),
        ), $this->connection->fetchAllAssociative('SELECT * FROM billing.reconciliation_issue WHERE resolved_at IS NULL ORDER BY detected_at'));
    }

    public function resolve(string $issueId, string $adminId, \DateTimeImmutable $at): bool
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $issueId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement("UPDATE billing.reconciliation_issue SET resolved_at = :at, resolved_by = :admin, resolution = 'manual' WHERE id = :id AND resolved_at IS NULL", ['at' => $at->format(\DATE_ATOM), 'admin' => $adminId, 'id' => $issueId]);
    }
}
