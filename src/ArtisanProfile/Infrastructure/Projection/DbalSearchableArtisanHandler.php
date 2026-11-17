<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Application\Search\PublicArtisanIds;
use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * Lecture des artisans publics. Position = centroïde de la commune du siège, sinon de la commune de départ de la zone
 * (RG-022 : jamais d'adresse exacte).
 */
final readonly class DbalSearchableArtisanHandler implements QueryHandler
{
    private const string PUBLIC = "p.status = 'verified' AND p.hidden_at IS NULL";

    public function __construct(private Connection $connection, private SettingsProvider $settings, private AgendaStore $agenda)
    {
    }

    /** @return SearchableArtisan|list<string>|null */
    public function __invoke(SearchableArtisanOf|PublicArtisanIds $query): SearchableArtisan|array|null
    {
        if ($query instanceof PublicArtisanIds) {
            return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT p.id FROM artisan.artisan_profile p WHERE '.self::PUBLIC.' ORDER BY p.id'));
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->profileId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.user_id, coalesce(p.trade_name, p.company_name) AS name, p.short_description, p.logo_file_id, p.jobs, p.qualifications,
                    p.completion_rate, p.verified_at, c.insee, c.name AS commune, c.department_code,
                    ST_Y(c.centroid::geometry) AS lat, ST_X(c.centroid::geometry) AS lng
             FROM artisan.artisan_profile p
             LEFT JOIN catalog.commune c ON c.insee = coalesce(p.hq_city_insee, p.area_center_insee)
             WHERE p.id = :id AND '.self::PUBLIC,
            ['id' => $query->profileId],
        );
        if (false === $row) {
            return null;
        }
        $jobs = DbalValue::json($row['jobs']);
        $jobs = \is_array($jobs) ? $jobs : [];
        $main = null;
        $ids = [];
        foreach ($jobs as $job) {
            if (\is_array($job) && \is_string($job['job'] ?? null)) {
                $ids[] = $job['job'];
                if (true === ($job['main'] ?? false)) {
                    $main = $job['job'];
                }
            }
        }
        $qualifications = DbalValue::json($row['qualifications']);
        $labels = [];
        foreach (\is_array($qualifications) ? $qualifications : [] as $q) {
            if (\is_array($q) && \is_string($q['name'] ?? null)) {
                $labels[] = $q['name'];
            }
        }

        return new SearchableArtisan(
            DbalValue::string($row['id']),
            DbalValue::string($row['user_id']),
            DbalValue::string($row['name']),
            null === $row['short_description'] ? null : DbalValue::string($row['short_description']),
            null === $row['logo_file_id'] ? null : DbalValue::string($row['logo_file_id']),
            $main,
            $ids,
            null === $row['insee'] ? null : DbalValue::string($row['insee']),
            null === $row['commune'] ? null : DbalValue::string($row['commune']),
            null === $row['department_code'] ? null : DbalValue::string($row['department_code']),
            is_numeric($row['lat']) ? (float) $row['lat'] : null,
            is_numeric($row['lng']) ? (float) $row['lng'] : null,
            $labels,
            DbalValue::int($row['completion_rate']),
            new \DateTimeImmutable(DbalValue::string($row['verified_at'])),
            $this->agenda->availableSoon($query->profileId, $this->settings->int(SettingKey::AvailabilityBadgeDays)),
        );
    }
}
