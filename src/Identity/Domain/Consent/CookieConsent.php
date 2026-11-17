<?php

declare(strict_types=1);

namespace App\Identity\Domain\Consent;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Choix de consentement d'un visiteur à un instant donné (CDC §3.2, RGPD art. 7 : preuve du consentement).
 * Toute catégorie non mentionnée est refusée : aucun consentement par défaut.
 */
final readonly class CookieConsent
{
    /** @var array<string, bool> catégorie => accordé */
    public array $choices;

    /**
     * @param array<string, mixed> $choices
     */
    public function __construct(
        array $choices,
        public int $policyVersion,
        public ConsentSource $source,
        public \DateTimeImmutable $givenAt,
        public \DateTimeImmutable $expiresAt,
    ) {
        if ($policyVersion < 1) {
            throw InvalidValue::for('Consentement', 'version de politique invalide');
        }
        if ($expiresAt <= $givenAt) {
            throw InvalidValue::for('Consentement', 'date d\'expiration antérieure au recueil');
        }
        $unknown = array_diff(array_keys($choices), array_map(static fn (CookieCategory $c): string => $c->value, CookieCategory::cases()));
        if ([] !== $unknown) {
            throw InvalidValue::for('Consentement', 'catégorie inconnue : '.implode(', ', $unknown));
        }

        $normalized = [CookieCategory::Necessary->value => true];
        foreach (CookieCategory::optional() as $category) {
            $normalized[$category->value] = true === ($choices[$category->value] ?? false);
        }
        $this->choices = $normalized;
    }

    public function isGranted(CookieCategory $category): bool
    {
        return true === ($this->choices[$category->value] ?? false);
    }

    public function isRefusedAll(): bool
    {
        foreach (CookieCategory::optional() as $category) {
            if ($this->isGranted($category)) {
                return false;
            }
        }

        return true;
    }
}
