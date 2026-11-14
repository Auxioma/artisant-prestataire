<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Subscription\SubscriptionStatus;

/** PR11.1 — abonnement en vigueur : forfait, prix HT souscrit, période, statut. */
final readonly class SubscriptionSummary
{
    public function __construct(
        public string $planId,
        public string $planName,
        public int $priceCents,
        public BillingInterval $interval,
        public SubscriptionStatus $status,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public bool $cancelAtPeriodEnd,
    ) {
    }

    /** Jours écoulés et durée de la période en cours (barre « Période en cours »). */
    public function elapsedDays(\DateTimeImmutable $now): int
    {
        if (null === $this->periodStart) {
            return 0;
        }

        return max(0, min($this->totalDays(), (int) $this->periodStart->diff($now)->days));
    }

    public function totalDays(): int
    {
        return null === $this->periodStart || null === $this->periodEnd ? 0 : max(1, (int) $this->periodStart->diff($this->periodEnd)->days);
    }
}
