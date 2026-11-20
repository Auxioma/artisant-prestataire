<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.1 — avis déposés sur la période et aujourd'hui publiés, et leur note moyenne. */
final readonly class ReviewDashboardMetrics implements DashboardMetricSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) AS published, avg(rating) AS average FROM review.review
              WHERE status = 'published' AND created_at >= :from AND created_at < :to",
            ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)],
        ) ?: [];

        return ['reviews_published' => DbalValue::int($row['published'] ?? 0), 'review_average' => DbalValue::nullableFloat($row['average'] ?? null)];
    }
}
