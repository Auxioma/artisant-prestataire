<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo;

use App\Catalog\Application\Geo\Port\GeoReferentialSource;
use App\Catalog\Application\Geo\Port\GeoReferentialStore;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\GeoPoint;
use App\Shared\Domain\ValueObject\InseeCode;
use App\Shared\Domain\ValueObject\Slug;

/**
 * BO07.1 : import du référentiel géographique (régions, départements, communes, codes postaux, centroïdes).
 * Idempotent, transactionnel, audité. Slugs « nom-codepostal » (annexe A : /{metier}/{ville-cp}) attribués une fois.
 */
final readonly class GeoReferentialImporter
{
    private const int BATCH_SIZE = 1000;
    /** Garde-fou : un import complet doit couvrir au moins 90 % des communes actives avant d'en désactiver. */
    private const float MIN_COVERAGE_FOR_DEACTIVATION = 0.9;
    private const string POSTAL_CODE_PATTERN = '/^\d{5}$/';
    private const string DEPARTMENT_PATTERN = '/^(\d{2}|2A|2B|97\d)$/';
    /** Collectivités d'outre-mer (975, 977, 978, 98x) : hors périmètre du MVP (P-35), comptées à part. */
    private const string OVERSEAS_COLLECTIVITY_PATTERN = '/^(97[5-8]|98\d)$/';

    public function __construct(
        private GeoReferentialStore $store,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function import(GeoReferentialSource $source, bool $deactivateMissing, ?string $actorId = null): ImportReport
    {
        $regions = iterator_to_array($source->regions(), false);
        $departments = iterator_to_array($source->departments(), false);
        $knownDepartments = array_fill_keys(array_map(static fn (DepartmentRecord $d): string => $d->code, $departments), true);

        $report = $this->transactions->run(function () use ($source, $regions, $departments, $knownDepartments, $deactivateMissing): ImportReport {
            $this->store->upsertRegions($regions);
            $this->store->upsertDepartments($departments);

            $owners = $this->store->slugOwners();
            $slugByInsee = array_flip($owners);
            $rejected = [];
            $outOfScope = 0;
            $present = [];
            $batch = [];
            $new = 0;
            foreach ($source->communes() as $record) {
                if (1 === preg_match(self::OVERSEAS_COLLECTIVITY_PATTERN, $record->departmentCode) && !isset($knownDepartments[$record->departmentCode])) {
                    ++$outOfScope;
                    continue;
                }
                try {
                    $row = $this->validate($record, $knownDepartments, $owners, $slugByInsee);
                } catch (InvalidValue $e) {
                    $rejected[] = $record->insee.' : '.$e->getMessage();
                    continue;
                }
                $owners[$row->slug] = $row->insee;
                $slugByInsee[$row->insee] = $row->slug;
                $present[] = $row->insee;
                $batch[] = $row;
                if (\count($batch) >= self::BATCH_SIZE) {
                    $new += $this->store->upsertCommunes($batch);
                    $batch = [];
                }
            }
            if ([] !== $batch) {
                $new += $this->store->upsertCommunes($batch);
            }

            $deactivated = 0;
            if ($deactivateMissing) {
                $active = $this->store->activeCommuneCount();
                if (\count($present) < self::MIN_COVERAGE_FOR_DEACTIVATION * $active) {
                    throw InvalidValue::for('Import géographique', \sprintf('import partiel (%d communes pour %d actives) : désactivation refusée', \count($present), $active));
                }
                $deactivated = $this->store->deactivateMissing($present);
            }

            return new ImportReport(\count($regions), \count($departments), \count($present), $new, $deactivated, $rejected, $outOfScope);
        });

        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: null === $actorId ? 'system' : 'admin',
            actorId: $actorId,
            action: 'catalog.geo_imported',
            subjectType: 'geo_referential',
            subjectId: 'communes',
            context: ['communes' => $report->communes, 'new' => $report->newCommunes, 'deactivated' => $report->deactivated, 'rejected' => \count($report->rejected), 'out_of_scope' => $report->outOfScope],
        ));

        return $report;
    }

    /**
     * @param array<string, true>   $knownDepartments
     * @param array<string, string> $owners           slug => INSEE
     * @param array<string, string> $slugByInsee      INSEE => slug
     */
    private function validate(CommuneRecord $record, array $knownDepartments, array $owners, array $slugByInsee): CommuneRow
    {
        $insee = new InseeCode($record->insee);
        $point = new GeoPoint($record->latitude, $record->longitude);
        $name = trim($record->name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw InvalidValue::for('Commune', 'nom invalide');
        }
        if (1 !== preg_match(self::DEPARTMENT_PATTERN, $record->departmentCode) || !isset($knownDepartments[$record->departmentCode])) {
            throw InvalidValue::for('Commune', 'département inconnu « '.$record->departmentCode.' »');
        }
        $postalCodes = array_values(array_unique(array_filter($record->postalCodes, static fn (string $cp): bool => 1 === preg_match(self::POSTAL_CODE_PATTERN, $cp))));
        sort($postalCodes);
        if (null !== $record->population && $record->population < 0) {
            throw InvalidValue::for('Commune', 'population négative');
        }

        // Slug existant conservé ; sinon « nom-codepostal » (le plus petit code), ou « nom-insee » sans code postal / en cas de collision.
        $existing = $slugByInsee[$insee->value] ?? null;
        if (null !== $existing) {
            $slug = $existing;
        } else {
            $slug = Slug::fromText($name.' '.($postalCodes[0] ?? $insee->value))->value;
            if (isset($owners[$slug]) && $owners[$slug] !== $insee->value) {
                $slug = Slug::fromText($name.' '.$insee->value)->value;
            }
        }

        return new CommuneRow($insee->value, $name, $slug, $postalCodes, $record->departmentCode, $record->population, $point->latitude, $point->longitude);
    }
}
