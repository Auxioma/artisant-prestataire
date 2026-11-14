<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/**
 * E-40 (abonnement activé), E-41 (renouvellement réussi), E-43 (changement de forfait, résiliation programmée ou
 * annulée), E-44 (échec de paiement, à chaque tentative), E-45 (suspension).
 */
interface SubscriptionMailer
{
    public function activated(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void;

    public const string CHANGE_PLAN = 'plan';
    public const string CHANGE_CANCEL_SCHEDULED = 'cancel_scheduled';
    public const string CHANGE_CANCEL_UNDONE = 'cancel_undone';

    /** @param self::CHANGE_* $kind */
    public function changed(string $userId, string $kind, string $planName, ?\DateTimeImmutable $effectiveOn): void;

    public function paymentFailed(string $userId, string $planName, int $amountDueCents, int $attempt, ?\DateTimeImmutable $nextAttemptAt, ?string $invoiceUrl): void;

    /** E-45 — période de grâce écoulée : abonnement suspendu, envoi de devis bloqué jusqu'au paiement. */
    public function suspended(string $userId, string $planName, int $graceDays, \DateTimeImmutable $cancelOn): void;

    public function renewed(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void;
}
