<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** E-40 (abonnement activé) et E-41 (renouvellement réussi), avec le lien vers la facture Stripe. */
interface SubscriptionMailer
{
    public function activated(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void;

    public function renewed(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void;
}
