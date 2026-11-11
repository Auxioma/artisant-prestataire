<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

use App\ArtisanProfile\Domain\Profile\Event\ArtisanCompanyDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanInsurancesDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanJobsChosen;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanProfileStarted;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanServiceAreaDefined;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Siret;

/**
 * Profil professionnel (A03, RG-041 à RG-043). Créé « incomplet » après la création du compte ; rempli étape par étape
 * (« Terminer plus tard » : la progression est conservée). Le compte utilisateur appartient au module Identity : seul
 * son identifiant est référencé ici.
 */
final class ArtisanProfile extends AggregateRoot
{
    /** Étapes du tunnel A03 (1 compte · 2 entreprise · 3 métiers · 4 zone · 5 assurances · 6 justificatifs · 7 profil). */
    public const int STEP_ACCOUNT = 1;
    public const int STEP_COMPANY = 2;
    public const int STEP_JOBS = 3;
    public const int STEP_AREA = 4;
    public const int STEP_INSURANCE = 5;
    public const int STEP_DOCUMENTS = 6;
    public const int STEP_PROFILE = 7;
    /** A03.7 — « description courte (300 car.) ». */
    public const int SHORT_DESCRIPTION_MAX = 300;
    /** Borne technique du nombre de labels déclarés. */
    public const int MAX_QUALIFICATIONS = 10;

    private ArtisanStatus $status = ArtisanStatus::Incomplete;
    private int $completedStep = self::STEP_ACCOUNT;
    private ?string $siret = null;
    private ?string $siren = null;
    private ?string $companyName = null;
    private ?string $tradeName = null;
    private ?string $legalForm = null;
    private ?string $ape = null;
    private ?string $vatNumber = null;
    private ?VatStatus $vatStatus = null;
    private ?string $hqStreet = null;
    private ?string $hqPostalCode = null;
    private ?string $hqCity = null;
    private ?string $hqInsee = null;
    private ?\DateTimeImmutable $companyCreatedOn = null;
    private ?\DateTimeImmutable $siretVerifiedAt = null;
    /** @var list<array{job: string, main: bool}> métiers exercés (projection artisan_job pour la recherche : T-208) */
    private array $jobs = [];
    private ?ServiceAreaMode $areaMode = null;
    private ?string $areaCenterInsee = null;
    private ?int $areaRadiusKm = null;
    /** @var list<string> */
    private array $areaDepartments = [];
    /** @var list<string> */
    private array $areaCommunes = [];
    /** @var list<array{kind: string, insurer: string, policy: string, valid_until: string}> */
    private array $insurances = [];
    /** @var list<array{name: string, number: string, valid_until: string}> */
    private array $qualifications = [];
    private ?string $shortDescription = null;
    private ?string $logoFileId = null;
    private ?\DateTimeImmutable $submittedAt = null;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private ArtisanProfileId $id,
        private string $userId,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function start(ArtisanProfileId $id, string $userId, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $userId)) {
            throw new ArtisanRuleViolation('Compte utilisateur invalide.');
        }
        $profile = new self($id, $userId, $now);
        $profile->recordEvent(new ArtisanProfileStarted($id->value, $userId, $now));

        return $profile;
    }

    /** A03.2 — l'unicité du SIRET (RG-041) est vérifiée par le cas d'usage et garantie par la base. */
    public function declareCompany(CompanyDetails $company, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->siret = $company->siret->value;
        $this->siren = $company->siret->siren();
        $this->companyName = $company->companyName;
        $this->tradeName = $company->tradeName;
        $this->legalForm = $company->legalForm;
        $this->ape = $company->ape;
        $this->vatNumber = $company->vatNumber;
        $this->vatStatus = $company->vatStatus;
        $this->hqStreet = $company->hqStreet;
        $this->hqPostalCode = $company->hqPostalCode;
        $this->hqCity = $company->hqCity;
        $this->hqInsee = $company->hqInsee;
        $this->companyCreatedOn = $company->createdOn;
        $this->siretVerifiedAt = $company->verified ? $now : null;
        $this->completedStep = max($this->completedStep, self::STEP_COMPANY);
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanCompanyDeclared($this->id->value, $company->verified, $now));
    }

    /**
     * A03.3 — 1 à `pro.max_jobs` métiers distincts, dont un principal.
     *
     * @param list<JobId> $jobIds
     */
    public function chooseJobs(array $jobIds, JobId $main, int $maxJobs, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_COMPANY, 'Déclarez d’abord votre entreprise.');
        $unique = [];
        foreach ($jobIds as $jobId) {
            $unique[$jobId->value] = $jobId;
        }
        if ([] === $unique) {
            throw new ArtisanRuleViolation('Choisissez au moins un métier.');
        }
        if (\count($unique) > $maxJobs) {
            throw new ArtisanRuleViolation(\sprintf('Vous pouvez choisir %d métiers au maximum.', $maxJobs));
        }
        if (!isset($unique[$main->value])) {
            throw new ArtisanRuleViolation('Le métier principal doit faire partie des métiers choisis.');
        }

        $this->jobs = [];
        foreach (array_keys($unique) as $value) {
            $this->jobs[] = ['job' => (string) $value, 'main' => $value === $main->value];
        }
        $this->completedStep = max($this->completedStep, self::STEP_JOBS);
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanJobsChosen($this->id->value, $now));
    }

    /** A03.4 — zone d'intervention ; modifiable à tout moment, effet immédiat pour les nouvelles demandes (RG-042). */
    public function defineServiceArea(ServiceArea $area, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_JOBS, 'Choisissez d’abord vos métiers.');
        $this->areaMode = $area->mode;
        $this->areaCenterInsee = $area->centerInsee;
        $this->areaRadiusKm = $area->radiusKm;
        $this->areaDepartments = $area->departments;
        $this->areaCommunes = $area->communes;
        $this->completedStep = max($this->completedStep, self::STEP_AREA);
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanServiceAreaDefined($this->id->value, $now));
    }

    /**
     * A03.5 — assurances (une par nature) et labels. Décennale / RC Pro obligatoires selon les métiers exercés (Q16) ;
     * une attestation dont la date de fin est passée est refusée.
     *
     * @param list<Insurance>     $insurances
     * @param list<Qualification> $qualifications
     */
    public function declareInsurances(array $insurances, array $qualifications, InsuranceRequirements $required, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_AREA, 'Définissez d’abord votre zone d’intervention.');
        $byKind = [];
        foreach ($insurances as $insurance) {
            if (isset($byKind[$insurance->kind->value])) {
                throw new ArtisanRuleViolation(\sprintf('%s déclarée deux fois.', $insurance->kind->label()));
            }
            if ($insurance->isExpiredOn($now)) {
                throw new ArtisanRuleViolation('Votre attestation d’assurance semble expirée.');
            }
            $byKind[$insurance->kind->value] = $insurance;
        }
        foreach ([[InsuranceKind::Decennial, $required->decennial], [InsuranceKind::ProfessionalLiability, $required->liability]] as [$kind, $mandatory]) {
            if ($mandatory && !isset($byKind[$kind->value])) {
                throw new ArtisanRuleViolation(\sprintf('%s obligatoire pour vos métiers.', $kind->label()));
            }
        }
        if ($required->any && [] === $byKind) {
            throw new ArtisanRuleViolation('Déclarez au moins une assurance (décennale ou RC Professionnelle).');
        }
        if (\count($qualifications) > self::MAX_QUALIFICATIONS) {
            throw new ArtisanRuleViolation(\sprintf('%d labels au maximum.', self::MAX_QUALIFICATIONS));
        }
        $names = [];
        foreach ($qualifications as $qualification) {
            if (isset($names[mb_strtolower($qualification->name)])) {
                throw new ArtisanRuleViolation(\sprintf('Le label « %s » est déclaré deux fois.', $qualification->name));
            }
            if ($qualification->isExpiredOn($now)) {
                throw new ArtisanRuleViolation(\sprintf('Le label « %s » semble expiré.', $qualification->name));
            }
            $names[mb_strtolower($qualification->name)] = true;
        }

        $this->insurances = array_values(array_map(static fn (Insurance $i): array => $i->toArray(), $byKind));
        $this->qualifications = array_map(static fn (Qualification $q): array => $q->toArray(), $qualifications);
        $this->completedStep = max($this->completedStep, self::STEP_INSURANCE);
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanInsurancesDeclared($this->id->value, $now));
    }

    /** A03.6 — étape franchie, pièces complètes ou non (« Terminer plus tard » : la vérification attend les pièces). */
    public function completeDocumentsStep(\DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_INSURANCE, 'Déclarez d’abord vos assurances.');
        $this->completedStep = max($this->completedStep, self::STEP_DOCUMENTS);
        $this->updatedAt = $now;
    }

    /** A03.7 — logo ou photo (fichier de `shared.stored_file`, exposé seulement une fois sain). */
    public function changeLogo(?string $fileId, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_DOCUMENTS, 'Déposez d’abord vos justificatifs.');
        $this->logoFileId = $fileId;
        $this->updatedAt = $now;
    }

    /** A03.7 — description courte, puis envoi du dossier : « en attente de vérification » (RG-043). */
    public function submit(string $shortDescription, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->assertStepDone(self::STEP_DOCUMENTS, 'Déposez d’abord vos justificatifs.');
        $description = Text::paragraph($shortDescription, self::SHORT_DESCRIPTION_MAX);
        if ('' === $description) {
            throw new ArtisanRuleViolation('Présentez votre activité en quelques mots.');
        }
        $this->shortDescription = $description;
        $this->completedStep = self::STEP_PROFILE;
        $this->updatedAt = $now;
        if (ArtisanStatus::Incomplete === $this->status) {
            $this->status = ArtisanStatus::PendingVerification;
            $this->submittedAt = $now;
            $this->recordEvent(new ArtisanSignupSubmitted($this->id->value, $this->userId, $now));
        }
    }

    public function id(): ArtisanProfileId
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function status(): ArtisanStatus
    {
        return $this->status;
    }

    public function completedStep(): int
    {
        return $this->completedStep;
    }

    public function siret(): ?Siret
    {
        return null === $this->siret ? null : new Siret($this->siret);
    }

    public function companyName(): ?string
    {
        return $this->companyName;
    }

    public function tradeName(): ?string
    {
        return $this->tradeName;
    }

    public function hqPostalCode(): ?string
    {
        return $this->hqPostalCode;
    }

    public function hqCity(): ?string
    {
        return $this->hqCity;
    }

    public function vatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function vatStatus(): ?VatStatus
    {
        return $this->vatStatus;
    }

    public function isSiretVerified(): bool
    {
        return null !== $this->siretVerifiedAt;
    }

    /** @return list<JobId> */
    public function jobIds(): array
    {
        return array_map(static fn (array $j): JobId => new JobId($j['job']), $this->jobs);
    }

    public function mainJobId(): ?JobId
    {
        foreach ($this->jobs as $job) {
            if ($job['main']) {
                return new JobId($job['job']);
            }
        }

        return null;
    }

    public function serviceArea(): ?ServiceArea
    {
        return null === $this->areaMode ? null : ServiceArea::restore($this->areaMode, $this->areaCenterInsee, $this->areaRadiusKm, $this->areaDepartments, $this->areaCommunes);
    }

    /** @return list<Insurance> */
    public function insurances(): array
    {
        return array_map(Insurance::fromArray(...), $this->insurances);
    }

    /** @return list<Qualification> */
    public function qualifications(): array
    {
        return array_map(Qualification::fromArray(...), $this->qualifications);
    }

    public function shortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function logoFileId(): ?string
    {
        return $this->logoFileId;
    }

    public function submittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    private function assertStepDone(int $step, string $message): void
    {
        if ($this->completedStep < $step) {
            throw new ArtisanRuleViolation($message);
        }
    }

    private function assertEditable(): void
    {
        if (ArtisanStatus::Incomplete !== $this->status && ArtisanStatus::PendingVerification !== $this->status) {
            throw new ArtisanRuleViolation('Le dossier ne peut plus être modifié par le tunnel d’inscription.');
        }
    }
}
