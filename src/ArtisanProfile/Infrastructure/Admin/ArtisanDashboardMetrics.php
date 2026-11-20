<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO01.1 — artisans vérifiés actifs en fin de période : fiche vérifiée à cette date, toujours vérifiée et visible
 * (l'historique des suspensions n'est pas conservé : une fiche suspendue depuis sort aussi du chiffre N-1).
 */
final readonly class ArtisanDashboardMetrics implements DashboardMetricSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['verified_artisans' => DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM artisan.artisan_profile WHERE status = 'verified' AND hidden_at IS NULL AND verified_at < :to",
            ['to' => $to->format(\DATE_ATOM)],
        ))];
    }
}
