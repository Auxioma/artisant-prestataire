<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

final readonly class SignupState
{
    /**
     * @param list<string>                                                                    $jobIds
     * @param list<string>                                                                    $areaDepartments
     * @param list<string>                                                                    $areaCommunes
     * @param list<array{kind: string, insurer: string, policy: string, valid_until: string}> $insurances
     * @param list<array{name: string, number: string, valid_until: string}>                  $qualifications
     */
    public function __construct(
        public int $completedStep,
        public ?string $siret,
        public ?string $companyName,
        public ?string $tradeName,
        public ?string $vatNumber,
        public ?string $vatStatus,
        public bool $siretVerified,
        public array $jobIds,
        public ?string $mainJobId,
        public ?string $hqPostalCode = null,
        public ?string $hqCity = null,
        public string $status = 'incomplete',
        public ?string $areaMode = null,
        public ?string $areaCenterInsee = null,
        public ?int $areaRadiusKm = null,
        public array $areaDepartments = [],
        public array $areaCommunes = [],
        public array $insurances = [],
        public array $qualifications = [],
        public ?string $shortDescription = null,
        public ?string $logoFileId = null,
        public bool $submitted = false,
        public ?string $statusReason = null,
        public ?string $statusComment = null,
    ) {
    }

    /** Le tunnel reste modifiable (incomplet ou en attente) ; après décision, seuls zone (RG-042) et justificatifs le sont. */
    public function isOpen(): bool
    {
        return \in_array($this->status, ['incomplete', 'pending_verification'], true);
    }

    /** Nom affiché sur la carte artisan : nom commercial, sinon raison sociale. */
    public function displayName(): ?string
    {
        return $this->tradeName ?? $this->companyName;
    }
}
