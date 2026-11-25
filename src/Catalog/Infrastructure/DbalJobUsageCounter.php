<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\JobUsageCounter;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO06 — références vivantes d'un métier : artisans qui l'exercent et demandes en cours (modération, ouvertes). */
final readonly class DbalJobUsageCounter implements JobUsageCounter
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(JobId $job): int
    {
        return DbalValue::int($this->connection->fetchOne(
            <<<'SQL'
                SELECT (SELECT count(*) FROM artisan.artisan_job WHERE job_id = :job)
                     + (SELECT count(*) FROM request.quote_request WHERE job_id = :job AND status IN ('pending_moderation', 'open', 'full'))
                SQL,
            ['job' => $job->value],
        ));
    }
}
