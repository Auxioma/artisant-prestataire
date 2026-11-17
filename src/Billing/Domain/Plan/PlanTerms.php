<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

/**
 * BO08.1 — contenu d'un forfait : nom, description, devis par période (ou illimité avec plafond d'usage raisonnable),
 * avantages, options techniques, badge, visibilité. Validé à la construction.
 */
final readonly class PlanTerms
{
    public const int NAME_MAX = 60;
    public const int TEXT_MAX = 500;
    public const int FEATURE_MAX = 120;
    public const int FEATURES_MAX = 12;
    public const int BADGE_MAX = 30;
    public const int CODE_MAX = 40;

    /** @param list<string> $features */
    public function __construct(
        public string $name,
        public ?string $description,
        public ?int $quotesPerPeriod,
        public bool $unlimited,
        public ?int $fairUseCap,
        public array $features,
        public ?int $maxOffers,
        public bool $featured,
        public bool $advancedStats,
        public ?string $badge,
        public PlanVisibility $visibility,
        public ?string $accessCode,
    ) {
        if ('' === trim($name) || mb_strlen($name) > self::NAME_MAX) {
            throw new PlanRuleViolation(\sprintf('Nom du forfait obligatoire (%d caractères au maximum).', self::NAME_MAX));
        }
        if (null !== $description && mb_strlen($description) > self::TEXT_MAX) {
            throw new PlanRuleViolation(\sprintf('Description trop longue (%d caractères au maximum).', self::TEXT_MAX));
        }
        if ($unlimited && null !== $quotesPerPeriod) {
            throw new PlanRuleViolation('Un forfait illimité n’a pas de nombre de devis par période.');
        }
        if (null !== $quotesPerPeriod && $quotesPerPeriod < 1) {
            throw new PlanRuleViolation('Le nombre de devis par période doit être positif.');
        }
        if (null !== $fairUseCap && (!$unlimited || $fairUseCap < 1)) {
            throw new PlanRuleViolation('Le plafond d’usage raisonnable ne s’applique qu’à un forfait illimité, et doit être positif.');
        }
        if (null !== $maxOffers && $maxOffers < 0) {
            throw new PlanRuleViolation('Le nombre d’offres ne peut pas être négatif.');
        }
        if (\count($features) > self::FEATURES_MAX) {
            throw new PlanRuleViolation(\sprintf('%d avantages au maximum.', self::FEATURES_MAX));
        }
        foreach ($features as $feature) {
            if ('' === trim($feature) || mb_strlen($feature) > self::FEATURE_MAX) {
                throw new PlanRuleViolation(\sprintf('Chaque avantage fait entre 1 et %d caractères.', self::FEATURE_MAX));
            }
        }
        if (null !== $badge && mb_strlen($badge) > self::BADGE_MAX) {
            throw new PlanRuleViolation(\sprintf('Badge trop long (%d caractères au maximum).', self::BADGE_MAX));
        }
        if (PlanVisibility::Private === $visibility && (null === $accessCode || 1 !== preg_match('/^[A-Z0-9-]{4,40}$/', $accessCode))) {
            throw new PlanRuleViolation('Un forfait privé a un code d’accès (4 à 40 caractères : lettres majuscules, chiffres, tirets).');
        }
        if (PlanVisibility::Public === $visibility && null !== $accessCode) {
            throw new PlanRuleViolation('Un forfait public n’a pas de code d’accès.');
        }
    }

    /** Prêt à la vente : un volume de devis (ou illimité avec plafond, Q5). */
    public function isSellable(): bool
    {
        return null !== $this->quotesPerPeriod || ($this->unlimited && null !== $this->fairUseCap);
    }
}
