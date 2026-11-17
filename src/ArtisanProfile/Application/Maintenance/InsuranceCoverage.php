<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Document\DocumentStatus;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\InsuranceKind;

/**
 * RG-130 — couverture des assurances obligatoires (selon les métiers, Q16) : déclarée ET justifiée par une attestation
 * validée, toutes deux en cours de validité.
 */
final readonly class InsuranceCoverage
{
    public function __construct(private SignupRequirements $requirements, private ArtisanDocumentRepository $documents)
    {
    }

    /** Assurance obligatoire la plus anciennement échue (ou absente : null + vrai) ; null si tout est couvert. */
    public function lapsedSince(ArtisanProfile $profile, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $oldest = null;
        foreach ($this->mandatory($profile) as $kind) {
            $insurance = null;
            foreach ($profile->insurances() as $declared) {
                if ($declared->kind === $kind) {
                    $insurance = $declared;
                }
            }
            if (null === $insurance) {
                continue; // absence : traitée à la vérification du dossier, pas par le masquage
            }
            if ($insurance->validUntil->format('Y-m-d') < $today->format('Y-m-d') && (null === $oldest || $insurance->validUntil < $oldest)) {
                $oldest = $insurance->validUntil;
            }
        }

        return $oldest;
    }

    /** Couverture rétablie : chaque assurance obligatoire déclarée valide et justifiée par une attestation validée valide. */
    public function isCovered(ArtisanProfile $profile, \DateTimeImmutable $today): bool
    {
        $day = $today->format('Y-m-d');
        $approved = [];
        foreach ($this->documents->currentOf($profile->id()->value) as $document) {
            if (DocumentStatus::Approved === $document->status() && null !== $document->expiresOn() && $document->expiresOn()->format('Y-m-d') >= $day) {
                $approved[$document->type()] = true;
            }
        }
        foreach ($this->mandatory($profile) as $kind) {
            $valid = false;
            foreach ($profile->insurances() as $declared) {
                $valid = $valid || ($declared->kind === $kind && $declared->validUntil->format('Y-m-d') >= $day);
            }
            if (!$valid || !isset($approved[self::documentType($kind)])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<InsuranceKind> */
    private function mandatory(ArtisanProfile $profile): array
    {
        $required = $this->requirements->insurances($profile);
        $kinds = array_values(array_filter([$required->decennial ? InsuranceKind::Decennial : null, $required->liability ? InsuranceKind::ProfessionalLiability : null]));
        if ([] === $kinds && $required->any) {
            // « au moins une assurance » : celle(s) déclarée(s) font foi.
            foreach ($profile->insurances() as $declared) {
                $kinds[$declared->kind->value] = $declared->kind;
            }
            $kinds = array_values($kinds);
        }

        return $kinds;
    }

    public static function documentType(InsuranceKind $kind): string
    {
        return InsuranceKind::Decennial === $kind ? 'insurance_decennial' : 'insurance_rc';
    }
}
