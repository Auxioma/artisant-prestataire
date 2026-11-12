<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

use App\ArtisanProfile\Domain\Profile\Event\ArtisanCompanyDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanHidden;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanInsurancesDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanJobsChosen;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanLogoChanged;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanMoreInfoRequested;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanProfileStarted;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanReinstated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanServiceAreaDefined;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSuspended;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanUnhidden;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Event\Integration\ProfessionalAccountVerified;
use App\Shared\Domain\ValueObject\Siret;

/**
 * Profil professionnel (A03, RG-041 à RG-043). Créé « incomplet » après la création du compte ; rempli étape par étape
 * (« Terminer plus tard » : la progression est conservée). Le compte utilisateur appartient au module Identity : seul
 * son identifiant est référencé ici.
 *
 * Machine à états RG-043 (ADR-0005) :
 *   incomplet ─submit→ en attente ─approve→ vérifié ─suspend→ suspendu ─reinstate→ vérifié
 *                      en attente ─reject→ refusé (motif)        en attente ─suspend→ suspendu ─reinstate→ en attente
 *                      en attente ─requestMoreInfo→ en attente (complément demandé)
 * Seul « vérifié » est public et peut envoyer des devis.
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
    private const int STATUS_COMMENT_MAX = 500;
    private const int TRADE_NAME_MAX = 120;

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
    private ?\DateTimeImmutable $verifiedAt = null;
    private ?string $statusReason = null;
    private ?string $statusComment = null;
    private ?\DateTimeImmutable $statusChangedAt = null;
    private ?string $statusChangedBy = null;
    private int $completionRate = 0;
    private ?\DateTimeImmutable $hiddenAt = null;
    private ?string $hiddenReason = null;
    private bool $phonePublic = false;
    /** @var array<string, mixed> */
    private array $presentation = [];
    /** @var list<array{title: string, price: ?string}> */
    private array $services = [];
    /** @var array<string, mixed> */
    private array $openingHours = [];
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
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
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
        if (ArtisanStatus::Rejected === $this->status) {
            throw new ArtisanRuleViolation('Votre dossier a été refusé : contactez-nous pour le réexaminer.');
        }
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
        if (ArtisanStatus::Verified !== $this->status) {
            $this->assertEditable(); // compte vérifié : renouvellement des attestations (RG-032, RG-130)
        }
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
        $this->recordEvent(new ArtisanLogoChanged($this->id->value, $this->userId, $now));
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

    /** BO03 — validation : badge « Vérifié », profil public, envoi de devis (crédit gratuit : RG-044). */
    public function approve(string $adminId, \DateTimeImmutable $now): void
    {
        $this->assertStatus(ArtisanStatus::PendingVerification, 'Seul un dossier en attente de vérification peut être validé.');
        $this->status = ArtisanStatus::Verified;
        $this->verifiedAt = $now;
        $this->changed($adminId, null, null, $now);
        $this->recordEvent(new ArtisanVerified($this->id->value, $this->userId, $adminId, $now));
        $this->recordEvent(new ProfessionalAccountVerified($this->id->value, $this->userId, $now));
    }

    /** BO03 — refus motivé (motif normalisé ; commentaire obligatoire pour « autre »). */
    public function reject(string $adminId, RejectionReason $reason, ?string $comment, \DateTimeImmutable $now): void
    {
        $this->assertStatus(ArtisanStatus::PendingVerification, 'Seul un dossier en attente de vérification peut être refusé.');
        $comment = Text::line($comment, 1, self::STATUS_COMMENT_MAX);
        if (RejectionReason::Other === $reason && null === $comment) {
            throw new ArtisanRuleViolation('Précisez le motif du refus.');
        }
        $this->status = ArtisanStatus::Rejected;
        $this->changed($adminId, $reason->value, $comment, $now);
        $this->recordEvent(new ArtisanRejected($this->id->value, $this->userId, $adminId, $reason->value, $comment, $now));
    }

    /** BO03 — demande de complément : le dossier reste en attente, l'artisan est prévenu. */
    public function requestMoreInfo(string $adminId, string $message, \DateTimeImmutable $now): void
    {
        $this->assertStatus(ArtisanStatus::PendingVerification, 'Seul un dossier en attente de vérification peut faire l’objet d’une demande de complément.');
        $message = Text::line($message, 1, self::STATUS_COMMENT_MAX) ?? throw new ArtisanRuleViolation('Précisez la pièce ou l’information attendue.');
        $this->changed($adminId, 'more_info', $message, $now);
        $this->recordEvent(new ArtisanMoreInfoRequested($this->id->value, $this->userId, $adminId, $message, $now));
    }

    /** Suspension (fraude, impayé, modération…) d'un compte vérifié ou en attente ; `adminId` null = automatique. */
    public function suspend(?string $adminId, string $reason, \DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [ArtisanStatus::Verified, ArtisanStatus::PendingVerification], true)) {
            throw new ArtisanRuleViolation('Seul un compte vérifié ou en attente de vérification peut être suspendu.');
        }
        $reason = Text::line($reason, 1, self::STATUS_COMMENT_MAX) ?? throw new ArtisanRuleViolation('Précisez le motif de la suspension.');
        $this->status = ArtisanStatus::Suspended;
        $this->changed($adminId, 'suspended', $reason, $now);
        $this->recordEvent(new ArtisanSuspended($this->id->value, $this->userId, $adminId, $reason, $now));
    }

    /** Levée de la suspension : retour à « vérifié » si le dossier l'avait été, sinon à « en attente ». */
    public function reinstate(?string $adminId, \DateTimeImmutable $now): void
    {
        $this->assertStatus(ArtisanStatus::Suspended, 'Ce compte n’est pas suspendu.');
        $this->status = null === $this->verifiedAt ? ArtisanStatus::PendingVerification : ArtisanStatus::Verified;
        $this->changed($adminId, null, null, $now);
        $this->recordEvent(new ArtisanReinstated($this->id->value, $this->userId, $adminId, $this->status->value, $now));
    }

    /** PR08.1 — taux de complétude recalculé par le cas d'usage (poids paramétrés, contenus d'autres écrans). */
    public function recordCompletion(ProfileCompletion $completion): void
    {
        $this->completionRate = $completion->percent;
    }

    /**
     * RG-130 : compte vérifié dont une assurance obligatoire est expirée depuis plus de `documents.hide_after_expiry_days`
     * jours — masqué automatiquement (ni public, ni destinataire de demandes, ni envoi de devis) sans être suspendu.
     */
    public function hide(string $reason, \DateTimeImmutable $now): void
    {
        if (ArtisanStatus::Verified !== $this->status || null !== $this->hiddenAt) {
            return;
        }
        $this->hiddenAt = $now;
        $this->hiddenReason = $reason;
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanHidden($this->id->value, $this->userId, $reason, $now));
    }

    /** Couverture rétablie (nouvelle attestation validée) : le profil réapparaît. */
    public function unhide(\DateTimeImmutable $now): void
    {
        if (null === $this->hiddenAt) {
            return;
        }
        $this->hiddenAt = null;
        $this->hiddenReason = null;
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanUnhidden($this->id->value, $this->userId, $now));
    }

    /** PR08.2 / PR08.3 — nom commercial (facultatif) et présentation publique. */
    public function updatePresentation(?string $tradeName, PublicPresentation $presentation, \DateTimeImmutable $now): void
    {
        $tradeName = null === $tradeName ? null : trim($tradeName);
        if (null !== $tradeName && mb_strlen($tradeName) > self::TRADE_NAME_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Nom commercial : %d caractères au maximum.', self::TRADE_NAME_MAX));
        }
        $this->tradeName = '' === $tradeName ? null : $tradeName;
        $this->presentation = $presentation->toArray();
        $this->publicProfileChanged($now);
    }

    /**
     * PR08.5 — prestations et tarifs indicatifs.
     *
     * @param list<ServiceOffer> $services
     */
    public function updateServices(array $services, \DateTimeImmutable $now): void
    {
        if (\count($services) > ServiceOffer::MAX) {
            throw new ArtisanRuleViolation(\sprintf('%d prestations au maximum.', ServiceOffer::MAX));
        }
        $this->services = array_map(static fn (ServiceOffer $s): array => ['title' => $s->title, 'price' => $s->price], array_values($services));
        $this->publicProfileChanged($now);
    }

    /** PR08.8 — horaires, urgences et option d'affichage du téléphone (RG-030). */
    public function updateAvailability(OpeningHours $hours, bool $phonePublic, \DateTimeImmutable $now): void
    {
        $this->openingHours = $hours->toArray();
        $this->phonePublic = $phonePublic;
        $this->publicProfileChanged($now);
    }

    public function presentation(): PublicPresentation
    {
        return PublicPresentation::fromArray($this->presentation);
    }

    /** @return list<ServiceOffer> */
    public function services(): array
    {
        return array_map(static fn (array $s): ServiceOffer => new ServiceOffer($s['title'], $s['price']), $this->services);
    }

    public function openingHours(): OpeningHours
    {
        return OpeningHours::fromArray($this->openingHours);
    }

    private function publicProfileChanged(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanPublicProfileUpdated($this->id->value, $this->userId, $now));
    }

    /** RG-030 — option « Afficher mon téléphone » (affichage au clic, tracé) ; désactivée par défaut. */
    public function showPhone(bool $public, \DateTimeImmutable $now): void
    {
        $this->phonePublic = $public;
        $this->updatedAt = $now;
    }

    public function isPhonePublic(): bool
    {
        return $this->phonePublic;
    }

    public function isHidden(): bool
    {
        return null !== $this->hiddenAt;
    }

    public function hiddenReason(): ?string
    {
        return $this->hiddenReason;
    }

    /** RG-043 / RG-130 : seul un compte vérifié et non masqué apparaît publiquement (P10) et reçoit les demandes (RG-062). */
    public function isPublic(): bool
    {
        return ArtisanStatus::Verified === $this->status && null === $this->hiddenAt;
    }

    /** RG-043 / RG-130 : seul un compte vérifié et non masqué peut envoyer des devis. */
    public function canSendQuotes(): bool
    {
        return $this->isPublic();
    }

    public function verifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function statusReason(): ?string
    {
        return $this->statusReason;
    }

    public function statusComment(): ?string
    {
        return $this->statusComment;
    }

    public function completionRate(): int
    {
        return $this->completionRate;
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

    public function ape(): ?string
    {
        return $this->ape;
    }

    public function legalForm(): ?string
    {
        return $this->legalForm;
    }

    public function companyCreatedOn(): ?\DateTimeImmutable
    {
        return $this->companyCreatedOn;
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

    /** @return list<array{job: string, main: bool}> */
    public function jobAssignments(): array
    {
        return $this->jobs;
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function submittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    private function assertStatus(ArtisanStatus $expected, string $message): void
    {
        if ($expected !== $this->status) {
            throw new ArtisanRuleViolation($message);
        }
    }

    private function changed(?string $by, ?string $reason, ?string $comment, \DateTimeImmutable $now): void
    {
        $this->statusReason = $reason;
        $this->statusComment = $comment;
        $this->statusChangedAt = $now;
        $this->statusChangedBy = $by;
        $this->updatedAt = $now;
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
