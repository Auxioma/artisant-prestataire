<?php

declare(strict_types=1);

namespace App\Identity\Domain\Legal;

/**
 * Documents légaux versionnés (P17, RG-002). CGU, CGV et confidentialité sont acceptés à l'inscription ; une nouvelle
 * version « substantielle » des CGU / CGV impose une ré-acceptation. Mentions légales et cookies : information seule.
 */
enum LegalDocumentType: string
{
    case TermsOfUse = 'cgu';
    case TermsOfSale = 'cgv';
    case ProTermsOfSale = 'cgv_pro';
    case Privacy = 'privacy';
    case LegalNotice = 'legal_notice';
    case Cookies = 'cookies';

    public function slug(): string
    {
        return match ($this) {
            self::TermsOfUse => 'cgu',
            self::TermsOfSale => 'cgv',
            self::ProTermsOfSale => 'cgv-professionnels',
            self::Privacy => 'confidentialite',
            self::LegalNotice => 'mentions-legales',
            self::Cookies => 'cookies',
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
            self::LegalNotice => 'Mentions légales',
            self::Cookies => 'Politique de cookies',
        };
    }

    /** RG-002 : une version « substantielle » de ces documents bloque l'utilisateur jusqu'à sa ré-acceptation. */
    public function requiresReacceptance(): bool
    {
        return \in_array($this, [self::TermsOfUse, self::TermsOfSale, self::ProTermsOfSale], true);
    }

    /** Ancienne adresse redirigée en 301 vers l'adresse du CDC (P17). */
    public static function legacySlug(string $slug): ?self
    {
        return 'politique-de-confidentialite' === $slug ? self::Privacy : null;
    }

    /** @return non-empty-string expression régulière des slugs (route) */
    public static function slugPattern(): string
    {
        return implode('|', array_map(static fn (self $t): string => $t->slug(), self::cases()));
    }
}
