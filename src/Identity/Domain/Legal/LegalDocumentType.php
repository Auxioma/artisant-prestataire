<?php

declare(strict_types=1);

namespace App\Identity\Domain\Legal;

/** Documents légaux versionnés soumis à acceptation (RG-002). Mentions légales et cookies : pages Content non versionnées. */
enum LegalDocumentType: string
{
    case TermsOfUse = 'cgu';
    case TermsOfSale = 'cgv';
    case ProTermsOfSale = 'cgv_pro';
    case Privacy = 'privacy';

    public function slug(): string
    {
        return match ($this) {
            self::TermsOfUse => 'cgu',
            self::TermsOfSale => 'cgv',
            self::ProTermsOfSale => 'cgv-professionnels',
            self::Privacy => 'politique-de-confidentialite',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::TermsOfUse => 'Conditions générales d’utilisation',
            self::TermsOfSale => 'Conditions générales de vente',
            self::ProTermsOfSale => 'Conditions générales de vente professionnelles',
            self::Privacy => 'Politique de confidentialité',
        };
    }
}
