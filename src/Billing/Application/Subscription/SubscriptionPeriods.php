<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** Périodes payées d'un abonnement (`billing.subscription_period`), une par facture Stripe. */
interface SubscriptionPeriods
{
    /** Vrai si la période vient d'être enregistrée ; faux si la facture l'avait déjà ouverte. */
    public function record(string $periodId, string $subscriptionId, string $stripeInvoiceId, \DateTimeImmutable $start, \DateTimeImmutable $end, int $credits, int $amountPaidCents, \DateTimeImmutable $at): bool;

    /** Période de l'abonnement qui précède celle qui commence à `$start`. */
    public function previousOf(string $subscriptionId, \DateTimeImmutable $start): ?string;
}
