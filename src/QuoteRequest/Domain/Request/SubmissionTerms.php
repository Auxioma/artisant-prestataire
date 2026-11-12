<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/**
 * Conditions figées à l'envoi : `quotes.max_per_request` (§4.2 : une valeur modifiée ne s'applique qu'aux nouvelles
 * demandes), durée de vie `requests.expiry_days` (RG-064), score de risque et seuil de modération (RG-063).
 */
final readonly class SubmissionTerms
{
    public const int RISK_MAX = 100;

    /** @param list<string> $riskSignals */
    public function __construct(
        public int $maxQuotes,
        public int $expiryDays,
        public int $riskScore,
        public array $riskSignals,
        public int $riskThreshold,
    ) {
        if ($maxQuotes < 1 || $expiryDays < 1 || $riskScore < 0 || $riskScore > self::RISK_MAX || $riskThreshold < 0 || $riskThreshold > self::RISK_MAX) {
            throw new RequestRuleViolation('Conditions de dépôt invalides.');
        }
    }

    /** RG-063 — au-delà du seuil, la demande passe par la file de modération avant toute diffusion. */
    public function needsModeration(): bool
    {
        return $this->riskScore >= $this->riskThreshold;
    }
}
