<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\MaintenanceCandidates;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalMaintenanceCandidates implements MaintenanceCandidates
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function documentsExpiringBy(\DateTimeImmutable $horizon): array
    {
        return $this->ids("SELECT id FROM artisan.document WHERE status IN ('approved', 'pending_review') AND expires_on <= :horizon ORDER BY expires_on", ['horizon' => $horizon->format('Y-m-d')]);
    }

    /** @return list<string> */
    public function verifiedProfilesWithInsuranceEndingBefore(\DateTimeImmutable $before): array
    {
        return $this->ids(
            <<<'SQL'
                SELECT id FROM artisan.artisan_profile p
                WHERE status = 'verified' AND hidden_at IS NULL
                  AND EXISTS (SELECT 1 FROM jsonb_array_elements(p.insurances) i WHERE (i->>'valid_until')::date < :before)
                SQL,
            ['before' => $before->format('Y-m-d')],
        );
    }

    /** @return list<string> */
    public function unfinishedDossiers(): array
    {
        return $this->ids("SELECT id FROM artisan.artisan_profile WHERE status IN ('incomplete', 'pending_verification') ORDER BY created_at");
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<string>
     */
    private function ids(string $sql, array $params = []): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn($sql, $params));
    }
}
