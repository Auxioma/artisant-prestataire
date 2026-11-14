<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

/** Registre de crédits en ajout seul (ADR-0006, RG-107) et son solde dénormalisé (`credit_balance`). */
interface CreditLedger
{
    /** RG-044 : crédit gratuit unique par artisan ; faux s'il avait déjà été attribué (rien n'est écrit). */
    public function grantFree(string $entryId, string $artisanId, string $userId, int $amount, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $at): bool;

    public function balanceOfUser(string $userId): ?CreditBalance;

    /**
     * §4.3 — crédits d'une période d'abonnement payée (compartiment « forfait »), une seule fois par période. Si le
     * report est désactivé (RG-109, `plans.rollover`), les crédits de forfait restants de la période précédente expirent
     * d'abord (écriture « expiration » tracée). Vrai si la période vient d'être créditée.
     */
    public function grantPeriod(string $entryId, string $artisanId, string $periodId, int $amount, ?\DateTimeImmutable $expiresAt, ?string $expirePreviousPeriodId, string $expiryEntryId, \DateTimeImmutable $at): bool;
}
