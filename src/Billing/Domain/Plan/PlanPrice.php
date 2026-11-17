<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

/**
 * Prix HT d'un forfait pour une périodicité, en centimes. Immuable : un changement de prix crée un nouveau prix (nouveau
 * Price Stripe) et n'affecte pas les abonnés existants (BO08.1, E-47 pour une migration explicite).
 */
final readonly class PlanPrice
{
    public function __construct(
        public string $id,
        public BillingInterval $interval,
        public int $amountCents,
        public \DateTimeImmutable $since,
        public ?string $stripePriceId = null,
    ) {
        if ($amountCents < 0) {
            throw new PlanRuleViolation('Le prix ne peut pas être négatif.');
        }
    }
}
