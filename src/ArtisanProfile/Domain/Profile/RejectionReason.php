<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** BO03 — motifs normalisés de refus d'un dossier (RG-043 « refusé (motif) »). `Other` impose un commentaire. */
enum RejectionReason: string
{
    case CompanyMismatch = 'company_mismatch';
    case IdentityMismatch = 'identity_mismatch';
    case InvalidDocuments = 'invalid_documents';
    case InsuranceMissing = 'insurance_missing';
    case DuplicateAccount = 'duplicate_account';
    case ActivityNotEligible = 'activity_not_eligible';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CompanyMismatch => 'Les informations de l’entreprise ne correspondent pas au SIRET',
            self::IdentityMismatch => 'La pièce d’identité ne correspond pas au dirigeant',
            self::InvalidDocuments => 'Justificatifs illisibles, incomplets ou non valides',
            self::InsuranceMissing => 'Assurance obligatoire absente ou expirée',
            self::DuplicateAccount => 'Un compte professionnel existe déjà pour cette entreprise',
            self::ActivityNotEligible => 'Activité non proposée sur la plateforme',
            self::Other => 'Autre motif',
        };
    }
}
