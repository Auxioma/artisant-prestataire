<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Dispatch;

use App\QuoteRequest\Application\Dispatch\AlertFrequency;
use App\QuoteRequest\Application\Dispatch\DispatchTarget;
use App\QuoteRequest\Application\Port\DispatchTargets;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * RG-062 en SQL (ADR-0004) : la zone est matérialisée dans `artisan.service_area_commune` à son enregistrement (rayon
 * PostGIS ou liste, RG-042), l'éligibilité se lit donc sans désynchronisation possible avec les profils.
 */
final readonly class DbalDispatchTargets implements DispatchTargets
{
    /** Profil vérifié et visible (RG-043, RG-130), compte actif, réception non mise en pause, non bloqué par le client. */
    private const string RECEIVABLE = <<<'SQL'
        SELECT p.id AS artisan_id, p.user_id, coalesce(rp.alert_frequency, 'immediate') AS frequency
        FROM artisan.artisan_profile p
        JOIN identity.user_account u ON u.id = p.user_id AND u.status = 'active'
        LEFT JOIN artisan.request_preference rp ON rp.artisan_id = p.id
        WHERE p.status = 'verified' AND p.hidden_at IS NULL AND rp.paused_at IS NULL AND p.user_id <> :client
          AND NOT EXISTS (SELECT 1 FROM request.client_artisan_block b WHERE b.client_id = :client AND b.artisan_id = p.id)
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function eligible(string $jobId, string $cityInsee, string $clientId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::RECEIVABLE.<<<'SQL'

                  AND EXISTS (SELECT 1 FROM artisan.artisan_job j WHERE j.artisan_id = p.id AND j.job_id = :job)
                  AND EXISTS (SELECT 1 FROM artisan.service_area_commune z WHERE z.artisan_id = p.id AND z.insee = :insee)
                ORDER BY p.verified_at, p.id
                SQL,
            ['client' => $clientId, 'job' => $jobId, 'insee' => $cityInsee],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function direct(string $artisanId, string $clientId): ?DispatchTarget
    {
        $row = $this->connection->fetchAssociative(self::RECEIVABLE.' AND p.id = :artisan', ['client' => $clientId, 'artisan' => $artisanId]);

        return false === $row ? null : self::hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): DispatchTarget
    {
        return new DispatchTarget(DbalValue::string($row['artisan_id']), DbalValue::string($row['user_id']), AlertFrequency::tryFrom(DbalValue::string($row['frequency'])) ?? AlertFrequency::Immediate);
    }
}
