<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\ArtisanProfile\Application\Port\VerificationDecisions;
use App\ArtisanProfile\Application\Signup\CompanyLookupResult;
use App\ArtisanProfile\Application\Signup\DocumentSlot;
use App\ArtisanProfile\Application\Signup\LookupCompany;
use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Document\DocumentStatus;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ServiceAreaMode;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\AccountReview;
use App\Shared\Application\Identity\AccountReviewDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/**
 * BO03.2 — file et dossier de vérification. Contrôles automatiques : cohérence nom / SIRET (répertoire Sirene interrogé
 * à l'ouverture), état administratif, échéances des assurances et labels, pièces manquantes, doublons de téléphone et
 * d'appareil. La cohérence APE ↔ métiers est affichée pour contrôle humain (pas de table de correspondance au MVP).
 */
final readonly class VerificationQueriesHandler implements QueryHandler
{
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private VerificationDecisions $decisions,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    /** @return list<QueueItem>|DossierView|string|null */
    public function __invoke(VerificationQueue|VerificationDossier|AdminDocumentFile $query): array|DossierView|string|null
    {
        return match (true) {
            $query instanceof VerificationQueue => $this->queue($query->limit),
            $query instanceof VerificationDossier => $this->dossier($query->profileId),
            default => $this->file($query),
        };
    }

    /** @return list<QueueItem> */
    private function queue(int $limit): array
    {
        $now = $this->clock->now();
        // Délai cible annoncé à l'artisan (A03.7), compté ici en heures calendaires : approximation prudente des heures ouvrées.
        $deadline = $this->settings->int(SettingKey::ProVerificationDelayHours) * self::SECONDS_PER_HOUR;
        $items = [];
        foreach ($this->profiles->pendingVerification($limit) as $profile) {
            $submitted = $profile->submittedAt() ?? $now;
            $items[] = new QueueItem(
                $profile->id()->value,
                $profile->tradeName() ?? (string) $profile->companyName(),
                $this->jobNames($profile)[0] ?? '—',
                trim(($profile->hqCity() ?? '').(null === $profile->hqPostalCode() ? '' : ' ('.substr($profile->hqPostalCode(), 0, 2).')')),
                $submitted,
                $now->getTimestamp() - $submitted->getTimestamp() > $deadline,
                'more_info' === $profile->statusReason(),
            );
        }

        return $items;
    }

    private function dossier(string $profileId): ?DossierView
    {
        $profile = $this->find($profileId);
        if (null === $profile) {
            return null;
        }
        $now = $this->clock->now();
        $documents = $this->documents->currentOf($profile->id()->value);
        $slots = $this->requirements->checklist($profile, $documents);
        $account = $this->queries->ask(new AccountReview($profile->userId()));
        $account = $account instanceof AccountReviewDetails ? $account : null;
        $sirene = $this->sirene($profile);
        $checks = [];

        // Cohérence nom / SIRET et état administratif.
        if (null === $sirene) {
            $checks[] = ['level' => 'warning', 'label' => 'Répertoire Sirene indisponible : contrôle du SIRET à refaire plus tard'];
        } elseif (CompanyLookupResult::FOUND !== $sirene['status']) {
            $checks[] = ['level' => 'error', 'label' => CompanyLookupResult::CLOSED === $sirene['status'] ? 'Établissement indiqué comme fermé dans Sirene' : 'SIRET introuvable dans Sirene'];
        } else {
            $same = null !== $sirene['name'] && self::normalize($sirene['name']) === self::normalize((string) $profile->companyName());
            $checks[] = $same
                ? ['level' => 'ok', 'label' => 'Cohérence nom / SIRET']
                : ['level' => 'warning', 'label' => \sprintf('Raison sociale déclarée (« %s ») différente de Sirene (« %s »)', $profile->companyName(), $sirene['name'] ?? 'non diffusée')];
        }
        if (!$profile->isSiretVerified()) {
            $checks[] = ['level' => 'warning', 'label' => 'SIRET saisi manuellement à l’inscription (répertoire indisponible)'];
        }
        $checks[] = ['level' => 'info', 'label' => \sprintf('APE %s · métiers : %s — cohérence à contrôler', $profile->ape() ?? 'non renseigné', implode(', ', $this->jobNames($profile)))];

        // Échéances (RG-032) : assurances et labels déclarés, signalés dès la première alerte paramétrée (J-30 par défaut).
        $alertDays = $this->settings->intList(SettingKey::DocumentsExpiryAlertDays);
        $warningDays = [] === $alertDays ? 0 : max($alertDays);
        foreach ([...array_map(static fn ($i): array => [$i->kind->label(), $i->validUntil], $profile->insurances()), ...array_map(static fn ($q): array => ['Label '.$q->name, $q->validUntil], $profile->qualifications())] as [$label, $until]) {
            $days = (int) $now->setTime(0, 0)->diff($until)->format('%r%a');
            if ($days < 0) {
                $checks[] = ['level' => 'error', 'label' => \sprintf('%s : expirée depuis %d jour%s', $label, -$days, -$days > 1 ? 's' : '')];
            } elseif ($days <= $warningDays) {
                $checks[] = ['level' => 'warning', 'label' => \sprintf('%s : expire dans %d jour%s', $label, $days, $days > 1 ? 's' : '')];
            }
        }

        $missing = array_filter($slots, static fn (DocumentSlot $s): bool => $s->required && !$s->provided());
        $checks[] = [] === $missing
            ? ['level' => 'ok', 'label' => 'Toutes les pièces obligatoires sont déposées']
            : ['level' => 'error', 'label' => 'Pièces manquantes ou refusées : '.implode(', ', array_map(static fn (DocumentSlot $s): string => $s->label, $missing))];

        if (null !== $account) {
            $duplicates = array_filter([
                $account->samePhoneAccounts > 0 ? \sprintf('%d autre%s compte%s avec ce téléphone', $account->samePhoneAccounts, $account->samePhoneAccounts > 1 ? 's' : '', $account->samePhoneAccounts > 1 ? 's' : '') : null,
                $account->sameDeviceAccounts > 0 ? \sprintf('%d autre%s compte%s sur le même appareil', $account->sameDeviceAccounts, $account->sameDeviceAccounts > 1 ? 's' : '', $account->sameDeviceAccounts > 1 ? 's' : '') : null,
            ]);
            $checks[] = [] === $duplicates ? ['level' => 'ok', 'label' => 'Aucun doublon de téléphone ni d’appareil'] : ['level' => 'warning', 'label' => 'Doublons possibles : '.implode(' ; ', $duplicates)];
            if (!$account->emailVerified) {
                $checks[] = ['level' => 'warning', 'label' => 'Adresse e-mail du compte non confirmée'];
            }
        }

        $byKey = [];
        foreach ($documents as $document) {
            $byKey[DocumentSlot::key($document->type(), $document->subject())] ??= $document;
        }

        return new DossierView(
            $profile->id()->value,
            $profile->status()->value,
            $profile->tradeName() ?? (string) $profile->companyName(),
            null === $account ? null : $account->firstName.' '.$account->lastName,
            $account?->email,
            $profile->submittedAt(),
            $this->jobNames($profile),
            $this->area($profile),
            [
                'siret' => $profile->siret()?->value,
                'company' => $profile->companyName(),
                'trade' => $profile->tradeName(),
                'ape' => $profile->ape(),
                'legal' => $profile->legalForm(),
                'created' => $profile->companyCreatedOn()?->format('d/m/Y'),
                'hq' => trim(($profile->hqCity() ?? '').(null === $profile->hqPostalCode() ? '' : ' ('.$profile->hqPostalCode().')')),
                'vat' => $profile->vatNumber(),
            ],
            $sirene,
            $checks,
            array_map(static function (DocumentSlot $slot) use ($byKey): array {
                $document = $byKey[$slot->slotKey()] ?? null;

                return ['slot' => $slot, 'id' => $document?->id(), 'reason' => $document instanceof ArtisanDocument && DocumentStatus::Rejected === $document->status() ? $document->reason() : null];
            }, $slots),
            $this->decisions->history($profile->id()->value),
            $profile->statusReason(),
            $profile->statusComment(),
            $profile->completionRate(),
        );
    }

    private function file(AdminDocumentFile $query): ?string
    {
        $document = $this->documents->find($query->documentId);

        return null !== $document && $document->artisanId() === $query->profileId && DocumentStatus::Shredded !== $document->status() ? $document->fileId() : null;
    }

    private function find(string $profileId): ?ArtisanProfile
    {
        return 1 === preg_match('/^[0-9a-f-]{36}$/', $profileId) ? $this->profiles->find(new ArtisanProfileId($profileId)) : null;
    }

    /** @return array{status: string, name: ?string, active: ?bool, ape: ?string, createdOn: ?\DateTimeImmutable, city: ?string}|null */
    private function sirene(ArtisanProfile $profile): ?array
    {
        $siret = $profile->siret();
        if (null === $siret) {
            return null;
        }
        $result = $this->queries->ask(new LookupCompany($profile->userId(), $siret->value));
        if (!$result instanceof CompanyLookupResult || CompanyLookupResult::UNAVAILABLE === $result->status) {
            return null;
        }
        $company = $result->company;

        return ['status' => $result->status, 'name' => $company?->name, 'active' => $company?->active, 'ape' => $company?->nafCode, 'createdOn' => $company?->createdOn, 'city' => null === $company ? null : trim(($company->city ?? '').' ('.($company->postalCode ?? '').')')];
    }

    /** @return list<string> métier principal en premier */
    private function jobNames(ArtisanProfile $profile): array
    {
        $ids = array_map(static fn ($j): string => $j->value, $profile->jobIds());
        $entries = [] === $ids ? [] : $this->queries->ask(new JobIndexEntries($ids));
        $main = $profile->mainJobId()?->value;
        $names = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $names[$entry->id] = $entry->name;
            }
        }
        uksort($names, static fn (string $a, string $b): int => ($b === $main) <=> ($a === $main));

        return array_values($names);
    }

    private function area(ArtisanProfile $profile): string
    {
        $area = $profile->serviceArea();
        if (null === $area) {
            return '—';
        }
        if (ServiceAreaMode::Radius === $area->mode) {
            $found = $this->queries->ask(new CommunesByInsee([(string) $area->centerInsee]));
            $commune = \is_array($found) ? ($found[0] ?? null) : null;

            return \sprintf('%d km autour de %s', $area->radiusKm, $commune instanceof CommuneSummary ? $commune->name : $area->centerInsee);
        }

        return \sprintf('%d département(s), %d commune(s)', \count($area->departments), \count($area->communes));
    }

    private static function normalize(string $name): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));

        return (string) preg_replace('/[^a-z0-9 ]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
    }
}
