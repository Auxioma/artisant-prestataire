<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO01.1 — devis envoyés sur la période, taux d'acceptation (devis envoyés sur la période et acceptés depuis), délai
 * moyen entre le dépôt d'une demande de la période et son premier devis envoyé.
 */
final readonly class QuoteDashboardMetrics implements DashboardMetricSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $period = ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)];
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) AS sent, count(*) FILTER (WHERE status = 'accepted') AS accepted
               FROM quote.quote WHERE sent_at >= :from AND sent_at < :to",
            $period,
        ) ?: [];
        $sent = DbalValue::int($row['sent'] ?? 0);
        $delay = DbalValue::nullableFloat($this->connection->fetchOne(
            'SELECT avg(EXTRACT(EPOCH FROM first_sent - r.submitted_at) / 60)
               FROM request.quote_request r
               JOIN LATERAL (SELECT min(q.sent_at) AS first_sent FROM quote.quote q WHERE q.request_id = r.id AND q.sent_at IS NOT NULL) f ON f.first_sent IS NOT NULL
              WHERE r.submitted_at >= :from AND r.submitted_at < :to',
            $period,
        ));

        return [
            'quotes_sent' => $sent,
            'acceptance_rate' => 0 === $sent ? null : DbalValue::int($row['accepted'] ?? 0) / $sent,
            'first_quote_delay_minutes' => $delay,
        ];
    }
}
