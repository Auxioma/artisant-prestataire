<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.1 — inscriptions clients et artisans de la période (comptes créés, effacés compris). */
final readonly class IdentityDashboardMetrics implements DashboardMetricSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) FILTER (WHERE kind = 'client') AS clients, count(*) FILTER (WHERE kind = 'artisan') AS artisans FROM identity.user_account WHERE created_at >= :from AND created_at < :to",
            ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)],
        ) ?: [];

        return ['client_signups' => DbalValue::int($row['clients'] ?? 0), 'artisan_signups' => DbalValue::int($row['artisans'] ?? 0)];
    }
}
