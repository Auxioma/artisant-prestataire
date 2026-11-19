<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardDailySource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.3 — demandes déposées par jour (heure de Paris), hors brouillons et refus. */
final readonly class RequestDaily implements DashboardDailySource
{
    public function __construct(private Connection $connection)
    {
    }

    public function daily(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT to_char(submitted_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS day, count(*) AS n
               FROM request.quote_request
              WHERE submitted_at >= :from AND submitted_at < :to AND status NOT IN ('draft', 'rejected')
              GROUP BY 1",
            ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)],
        ) as $row) {
            $days[DbalValue::string($row['day'])] = DbalValue::int($row['n']);
        }

        return ['requests' => $days];
    }
}
