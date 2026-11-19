<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardDailySource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.3 — devis envoyés par jour (heure de Paris). */
final readonly class QuoteDaily implements DashboardDailySource
{
    public function __construct(private Connection $connection)
    {
    }

    public function daily(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT to_char(sent_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS day, count(*) AS n
               FROM quote.quote WHERE sent_at >= :from AND sent_at < :to GROUP BY 1",
            ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)],
        ) as $row) {
            $days[DbalValue::string($row['day'])] = DbalValue::int($row['n']);
        }

        return ['quotes_sent' => $days];
    }
}
