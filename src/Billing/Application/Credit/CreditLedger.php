<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

/** Registre de crédits en ajout seul (ADR-0006, RG-107) et son solde dénormalisé (`credit_balance`). */
interface CreditLedger
{
    /** RG-044 : crédit gratuit unique par artisan ; faux s'il avait déjà été attribué (rien n'est écrit). */
    public function grantFree(string $entryId, string $artisanId, string $userId, int $amount, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $at): bool;

    public function balanceOfUser(string $userId): ?CreditBalance;
}
