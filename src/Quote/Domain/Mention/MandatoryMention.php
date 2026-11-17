<?php

declare(strict_types=1);

namespace App\Quote\Domain\Mention;

/** RG-120 — mentions obligatoires d'un devis de travaux contrôlées avant envoi (liste administrable). */
enum MandatoryMention: string
{
    case CompanyIdentity = 'company_identity';
    case DateAndValidity = 'date_validity';
    case DetailedBreakdown = 'detailed_breakdown';
    case Insurance = 'insurance';
    case TravelCosts = 'travel_costs';
    case FreeQuote = 'free_quote';

    public function label(): string
    {
        return match ($this) {
            self::CompanyIdentity => 'Identification de l’entreprise et SIRET',
            self::DateAndValidity => 'Date et durée de validité',
            self::DetailedBreakdown => 'Décompte détaillé, prix HT / TTC, TVA par taux',
            self::Insurance => 'Assurance professionnelle (métiers soumis)',
            self::TravelCosts => 'Frais de déplacement',
            self::FreeQuote => 'Mention « Devis gratuit » ajoutée automatiquement',
        };
    }
}
