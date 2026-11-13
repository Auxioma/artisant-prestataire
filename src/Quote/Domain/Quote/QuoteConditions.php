<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** PR04.1 / PR04.3 — validité (1 à 180 j), délai d'exécution, début possible, paiement, garanties, conditions, note. */
final readonly class QuoteConditions
{
    public const int VALIDITY_MIN = 1;
    public const int VALIDITY_MAX = 180;
    public const int TEXT_MAX = 2000;

    /** @param list<string> $paymentMethods */
    public function __construct(
        public int $validityDays,
        public ?string $executionDelay = null,
        public ?\DateTimeImmutable $startDate = null,
        public ?string $paymentTerms = null,
        public array $paymentMethods = [],
        public ?string $guarantees = null,
        public ?string $specialConditions = null,
        public ?string $clientNote = null,
    ) {
        if ($validityDays < self::VALIDITY_MIN || $validityDays > self::VALIDITY_MAX) {
            throw new QuoteRuleViolation('Durée de validité invalide.');
        }
        foreach ([$executionDelay, $paymentTerms, $guarantees, $specialConditions, $clientNote] as $text) {
            if (null !== $text && mb_strlen($text) > self::TEXT_MAX) {
                throw new QuoteRuleViolation(\sprintf('Un texte des conditions dépasse %d caractères.', self::TEXT_MAX));
            }
        }
    }
}
