<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\PublicProfile\ArtisanPhone;
use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicCredential;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * Fiche publique P10. Une assurance ou une qualification n'est « vérifiée » que si le justificatif correspondant est
 * validé par l'équipe et non échu à la date du jour (RG-032) ; sinon elle est affichée comme déclarée.
 */
final readonly class DbalPublicArtisanProfileHandler implements QueryHandler
{
    private const array INSURANCES = [
        'decennial' => ['type' => 'insurance_decennial', 'label' => 'Assurance décennale'],
        'rc_pro' => ['type' => 'insurance_rc', 'label' => 'Responsabilité civile professionnelle'],
    ];
    private const array CHECKS = [
        'identity' => 'Pièce d’identité du dirigeant',
        'kbis' => 'Extrait Kbis ou avis de situation INSEE',
        'insurance_decennial' => 'Attestation d’assurance décennale',
        'insurance_rc' => 'Attestation d’assurance RC Professionnelle',
    ];
    private const string SIRENE_CHECK = 'SIRET actif au répertoire Sirene';

    public function __construct(private Connection $connection, private QueryBus $queries, private Clock $clock)
    {
    }

    public function __invoke(PublicArtisanProfile $query): ?PublicProfileView
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->profileId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT p.*, c.insee, c.name AS commune, c.department_code
             FROM artisan.artisan_profile p
             LEFT JOIN catalog.commune c ON c.insee = coalesce(p.hq_city_insee, p.area_center_insee)
             WHERE p.id = :id AND p.status = 'verified' AND p.hidden_at IS NULL",
            ['id' => $query->profileId],
        );
        if (false === $row) {
            return null;
        }
        $today = $this->clock->now()->format('Y-m-d');
        $valid = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT type, subject FROM artisan.document WHERE artisan_id = :id AND status = 'approved' AND (expires_on IS NULL OR expires_on >= :today)",
            ['id' => $query->profileId, 'today' => $today],
        ) as $document) {
            $valid[DbalValue::string($document['type']).'|'.(DbalValue::nullableString($document['subject']) ?? '')] = true;
        }

        $jobs = DbalValue::json($row['jobs']);
        $main = null;
        $jobIds = [];
        foreach (\is_array($jobs) ? $jobs : [] as $job) {
            if (\is_array($job) && \is_string($job['job'] ?? null)) {
                $jobIds[] = $job['job'];
                $main = true === ($job['main'] ?? false) ? $job['job'] : $main;
            }
        }

        $insurances = [];
        $declared = DbalValue::json($row['insurances']);
        foreach (\is_array($declared) ? $declared : [] as $insurance) {
            $kind = \is_array($insurance) && \is_string($insurance['kind'] ?? null) ? self::INSURANCES[$insurance['kind']] ?? null : null;
            if (null !== $kind && \is_array($insurance)) {
                $until = self::date($insurance['valid_until'] ?? null);
                $insurances[] = new PublicCredential($kind['label'], \is_string($insurance['insurer'] ?? null) ? $insurance['insurer'] : null, $until, isset($valid[$kind['type'].'|']) && (null === $until || $until->format('Y-m-d') >= $today));
            }
        }
        $qualifications = [];
        $declared = DbalValue::json($row['qualifications']);
        foreach (\is_array($declared) ? $declared : [] as $qualification) {
            if (\is_array($qualification) && \is_string($qualification['name'] ?? null)) {
                $until = self::date($qualification['valid_until'] ?? null);
                $qualifications[] = new PublicCredential($qualification['name'], null, $until, isset($valid['qualification|'.$qualification['name']]) && (null === $until || $until->format('Y-m-d') >= $today));
            }
        }

        $checks = null === $row['siret_verified_at'] ? [] : [self::SIRENE_CHECK];
        foreach (self::CHECKS as $type => $label) {
            if (isset($valid[$type.'|'])) {
                $checks[] = $label;
            }
        }
        $departments = DbalValue::json($row['area_departments']);
        $communes = DbalValue::json($row['area_communes']);
        $profileId = DbalValue::string($row['id']);

        return new PublicProfileView(
            profileId: $profileId,
            name: DbalValue::nullableString($row['trade_name']) ?? DbalValue::string($row['company_name']),
            companyName: DbalValue::string($row['company_name']),
            tradeName: DbalValue::nullableString($row['trade_name']),
            description: DbalValue::nullableString($row['short_description']),
            hasLogo: null !== $row['logo_file_id'],
            mainJobId: $main,
            jobIds: $jobIds,
            communeInsee: DbalValue::nullableString($row['insee']),
            communeName: DbalValue::nullableString($row['commune']),
            departmentCode: DbalValue::nullableString($row['department_code']),
            postalCode: null === $row['hq_city_insee'] ? null : DbalValue::nullableString($row['hq_postal_code']),
            siret: DbalValue::string($row['siret']),
            legalForm: DbalValue::nullableString($row['legal_form']),
            ape: DbalValue::nullableString($row['ape']),
            vatNumber: DbalValue::nullableString($row['vat_number']),
            companyCreatedOn: self::date($row['company_created_on']),
            areaMode: DbalValue::nullableString($row['area_mode']),
            areaCenterInsee: DbalValue::nullableString($row['area_center_insee']),
            areaRadiusKm: null === $row['area_radius_km'] ? null : DbalValue::int($row['area_radius_km']),
            areaDepartments: array_values(array_filter(\is_array($departments) ? $departments : [], \is_string(...))),
            areaCommunesCount: \is_array($communes) ? \count($communes) : 0,
            insurances: $insurances,
            qualifications: $qualifications,
            verifiedChecks: $checks,
            verifiedAt: new \DateTimeImmutable(DbalValue::string($row['verified_at'])),
            memberSince: new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            phoneAvailable: null !== $this->queries->ask(new ArtisanPhone($profileId)),
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? new \DateTimeImmutable(substr($value, 0, 10)) : null;
    }
}
