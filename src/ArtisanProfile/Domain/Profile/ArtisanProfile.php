<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

use App\ArtisanProfile\Domain\Profile\Event\ArtisanCompanyDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanJobsChosen;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanProfileStarted;
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
        if (self::STEP_COMPANY > $this->completedStep) {
            throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        }
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

    private function assertEditable(): void
    {
        if (ArtisanStatus::Incomplete !== $this->status && ArtisanStatus::PendingVerification !== $this->status) {
            throw new ArtisanRuleViolation('Le dossier ne peut plus être modifié par le tunnel d’inscription.');
        }
    }
}
