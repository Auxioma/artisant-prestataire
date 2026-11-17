<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\PhoneReveals;
use Doctrine\DBAL\Connection;

final readonly class DbalPhoneReveals implements PhoneReveals
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $profileId, ?string $visitorHash, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO artisan.phone_reveal (id, artisan_id, visitor_hash, revealed_at) VALUES (:id, :artisan, :visitor, :at)',
            ['id' => $id, 'artisan' => $profileId, 'visitor' => $visitorHash, 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
