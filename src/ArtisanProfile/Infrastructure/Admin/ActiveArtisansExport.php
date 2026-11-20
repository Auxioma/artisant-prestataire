<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Admin;

use App\Shared\Application\Admin\ScheduledExportSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO15.2 — export hebdomadaire des artisans vérifiés et visibles (raison sociale, SIRET : données personnelles). */
final readonly class ActiveArtisansExport implements ScheduledExportSource
{
    public const string KEY = 'artisans_actifs';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Artisans actifs';
    }

    public function schedule(): string
    {
        return 'Hebdomadaire · lundi 06:00';
    }

    public function periodLabel(\DateTimeImmutable $now): string
    {
        return 'au '.$now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }

    public function header(): array
    {
        return ['nom', 'siret', 'metiers', 'code_postal', 'completude', 'verifie_le'];
    }

    public function rows(\DateTimeImmutable $now): iterable
    {
        foreach ($this->connection->iterateAssociative(
            "SELECT coalesce(p.trade_name, p.company_name, '') AS name, coalesce(p.siret, '') AS siret, p.hq_postal_code, p.completion_rate, p.verified_at,
                    coalesce((SELECT string_agg(j.name, ', ' ORDER BY aj.is_main DESC, j.name) FROM artisan.artisan_job aj JOIN catalog.job j ON j.id = aj.job_id WHERE aj.artisan_id = p.id), '') AS jobs
               FROM artisan.artisan_profile p
              WHERE p.status = 'verified' AND p.hidden_at IS NULL
              ORDER BY name",
        ) as $r) {
            yield [
                DbalValue::string($r['name']), DbalValue::string($r['siret']), DbalValue::string($r['jobs']),
                DbalValue::nullableString($r['hq_postal_code']) ?? '', (string) DbalValue::int($r['completion_rate'] ?? 0),
                null === $r['verified_at'] ? '' : new \DateTimeImmutable(DbalValue::string($r['verified_at']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'),
            ];
        }
    }
}
