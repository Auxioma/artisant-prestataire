<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO01.1 — demandes déposées sur la période (hors brouillons et demandes refusées en modération) et part de celles qui
 * ont reçu au moins un devis.
 */
final readonly class RequestDashboardMetrics implements DashboardMetricSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) AS submitted, count(*) FILTER (WHERE received_quotes_count > 0) AS answered
               FROM request.quote_request
              WHERE submitted_at >= :from AND submitted_at < :to AND status NOT IN ('draft', 'rejected')",
            ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)],
        ) ?: [];
        $submitted = DbalValue::int($row['submitted'] ?? 0);

        return [
            'requests_submitted' => $submitted,
            'requests_with_quote_rate' => 0 === $submitted ? null : DbalValue::int($row['answered'] ?? 0) / $submitted,
        ];
    }
}
