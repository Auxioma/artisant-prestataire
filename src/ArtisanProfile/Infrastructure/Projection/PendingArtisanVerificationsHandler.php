<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\Shared\Application\Admin\PendingArtisanVerifications;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class PendingArtisanVerificationsHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(PendingArtisanVerifications $query): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.artisan_profile WHERE status = 'pending_verification'"));
    }
}
