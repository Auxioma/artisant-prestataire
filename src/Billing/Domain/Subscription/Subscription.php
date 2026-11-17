<?php

declare(strict_types=1);

namespace App\Billing\Domain\Subscription;

use App\Billing\Domain\Plan\BillingInterval;
use App\Shared\Domain\AggregateRoot;

/**
 * §4.3 — abonnement d'un artisan à un forfait, miroir de la Subscription Stripe. Créé « en attente » au départ vers le
 * paiement (PR12) ; seuls les webhooks le font avancer (T-604) : activation, renouvellement, retard, suspension,
 * résiliation programmée (annulable tant que la période court), annulation.
 */
final class Subscription extends AggregateRoot
{
    private SubscriptionStatus $status = SubscriptionStatus::Incomplete;
    private ?string $stripeSubscriptionId = null;
    private ?\DateTimeImmutable $periodStart = null;
    private ?\DateTimeImmutable $periodEnd = null;
    private bool $cancelAtPeriodEnd = false;
    private ?\DateTimeImmutable $pastDueSince = null;
    private ?string $scheduledPriceId = null;
    private ?\DateTimeImmutable $scheduledOn = null;

    private function __construct(
        private readonly SubscriptionId $id,
        private readonly string $artisanId,
        private string $planId,
        private string $planPriceId,
        private BillingInterval $interval,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function start(SubscriptionId $id, string $artisanId, string $planId, string $planPriceId, BillingInterval $interval, \DateTimeImmutable $now): self
    {
        return new self($id, $artisanId, $planId, $planPriceId, $interval, $now, $now);
    }

    /** Reconstitution depuis le stockage. */
    public static function restore(SubscriptionId $id, string $artisanId, string $planId, string $planPriceId, BillingInterval $interval, SubscriptionStatus $status, ?string $stripeSubscriptionId, ?\DateTimeImmutable $periodStart, ?\DateTimeImmutable $periodEnd, bool $cancelAtPeriodEnd, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt, ?\DateTimeImmutable $pastDueSince = null, ?string $scheduledPriceId = null, ?\DateTimeImmutable $scheduledOn = null): self
    {
        $subscription = new self($id, $artisanId, $planId, $planPriceId, $interval, $createdAt, $updatedAt);
        $subscription->status = $status;
        $subscription->stripeSubscriptionId = $stripeSubscriptionId;
        $subscription->periodStart = $periodStart;
        $subscription->periodEnd = $periodEnd;
        $subscription->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        $subscription->pastDueSince = $pastDueSince;
        [$subscription->scheduledPriceId, $subscription->scheduledOn] = null === $scheduledPriceId || null === $scheduledOn ? [null, null] : [$scheduledPriceId, $scheduledOn];

        return $subscription;
    }

    /** Paiement confirmé par Stripe (webhook) : première période ouverte. */
    public function activate(string $stripeSubscriptionId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, \DateTimeImmutable $now): void
    {
        if (SubscriptionStatus::Cancelled === $this->status) {
            throw new SubscriptionRuleViolation('Un abonnement annulé ne se réactive pas : nouvelle souscription.');
        }
        $this->guardPeriod($periodStart, $periodEnd);
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->status = SubscriptionStatus::Active;
        [$this->periodStart, $this->periodEnd] = [$periodStart, $periodEnd];
        $this->updatedAt = $now;
    }

    /** Nouvelle période payée (renouvellement) ; un retard ou une suspension sont levés. */
    public function renew(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, \DateTimeImmutable $now): void
    {
        $this->guardLive();
        $this->guardPeriod($periodStart, $periodEnd);
        $this->status = SubscriptionStatus::Active;
        $this->pastDueSince = null;
        [$this->periodStart, $this->periodEnd] = [$periodStart, $periodEnd];
        $this->updatedAt = $now;
    }

    /** Échec de prélèvement : la période de grâce (billing.grace_days) court depuis le premier échec. */
    public function markPastDue(\DateTimeImmutable $now): void
    {
        $this->guardLive();
        if (SubscriptionStatus::Active === $this->status) {
            $this->status = SubscriptionStatus::PastDue;
            $this->pastDueSince ??= $now;
            $this->updatedAt = $now;
        }
    }

    /** Grâce écoulée (billing.grace_days) : envoi de devis bloqué (RG-106). */
    public function suspend(\DateTimeImmutable $now): void
    {
        $this->guardLive();
        $this->pastDueSince ??= $now;
        $this->status = SubscriptionStatus::Suspended;
        $this->updatedAt = $now;
    }

    /** Résiliation sans frais, effective en fin de période payée (§4.3). */
    public function scheduleCancellation(\DateTimeImmutable $now): void
    {
        $this->guardLive();
        $this->cancelAtPeriodEnd = true;
        [$this->scheduledPriceId, $this->scheduledOn] = [null, null];
        $this->updatedAt = $now;
    }

    /** Annulation de la résiliation, tant que la période court. */
    public function resume(\DateTimeImmutable $now): void
    {
        $this->guardLive();
        if (null !== $this->periodEnd && $now >= $this->periodEnd) {
            throw new SubscriptionRuleViolation('La période est terminée : la résiliation ne peut plus être annulée.');
        }
        $this->cancelAtPeriodEnd = false;
        $this->updatedAt = $now;
    }

    public function changePlan(string $planId, string $planPriceId, BillingInterval $interval, \DateTimeImmutable $now): void
    {
        $this->guardLive();
        [$this->planId, $this->planPriceId, $this->interval] = [$planId, $planPriceId, $interval];
        if ($this->scheduledPriceId === $planPriceId) {
            [$this->scheduledPriceId, $this->scheduledOn] = [null, null];
        }
        $this->updatedAt = $now;
    }

    /** Descente de forfait ou retour au mensuel : effet à l'échéance (§4.3), en attendant affiché sur PR11. */
    public function scheduleChange(string $planPriceId, \DateTimeImmutable $on, \DateTimeImmutable $now): void
    {
        $this->guardLive();
        if ($this->cancelAtPeriodEnd) {
            throw new SubscriptionRuleViolation('Une résiliation est programmée : annulez-la avant de changer de forfait.');
        }
        [$this->scheduledPriceId, $this->scheduledOn] = [$planPriceId, $on];
        $this->updatedAt = $now;
    }

    public function clearScheduledChange(\DateTimeImmutable $now): void
    {
        [$this->scheduledPriceId, $this->scheduledOn] = [null, null];
        $this->updatedAt = $now;
    }

    public function scheduledPriceId(): ?string
    {
        return $this->scheduledPriceId;
    }

    public function scheduledOn(): ?\DateTimeImmutable
    {
        return $this->scheduledOn;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = SubscriptionStatus::Cancelled;
        $this->cancelAtPeriodEnd = false;
        $this->updatedAt = $now;
    }

    public function id(): SubscriptionId
    {
        return $this->id;
    }

    public function artisanId(): string
    {
        return $this->artisanId;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function planPriceId(): string
    {
        return $this->planPriceId;
    }

    public function interval(): BillingInterval
    {
        return $this->interval;
    }

    public function status(): SubscriptionStatus
    {
        return $this->status;
    }

    public function stripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function periodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function cancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function pastDueSince(): ?\DateTimeImmutable
    {
        return $this->pastDueSince;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function guardLive(): void
    {
        if (!$this->status->isLive()) {
            throw new SubscriptionRuleViolation('Abonnement non actif : action impossible.');
        }
    }

    private function guardPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if ($end <= $start) {
            throw new SubscriptionRuleViolation('Période d’abonnement invalide.');
        }
    }
}
