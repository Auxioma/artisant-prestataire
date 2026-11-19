<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Admin;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.2 — dossiers à vérifier et artisans ayant au moins un justificatif à contrôler. */
final readonly class ArtisanQueues implements AdminQueueSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function queues(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT (SELECT count(*) FROM artisan.artisan_profile WHERE status = 'pending_verification') AS dossiers,
                    (SELECT count(DISTINCT artisan_id) FROM artisan.document WHERE status = 'pending_review') AS documents",
        ) ?: [];

        return ['artisans_pending' => DbalValue::int($row['dossiers'] ?? 0), 'documents_pending' => DbalValue::int($row['documents'] ?? 0)];
    }
}
