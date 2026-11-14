<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

use App\Billing\Domain\Credit\CreditBucket;

/**
 * ADR-0006 — compte de crédits d'un artisan vu de l'envoi d'un devis (RG-100, RG-101, RG-104). Appelé dans la
 * transaction de l'envoi, **après** le verrou de la demande (ordre fixe : demande puis compte).
 */
interface QuoteCreditAccount
{
    /** Verrouille le compte (`FOR UPDATE`) et renvoie le solde par compartiment ; null si aucun compte. */
    public function lockForQuote(string $artisanId): ?CreditBalance;

    /** RG-106 / §4.3 — abonnement suspendu pour impayé (grâce écoulée) : envoi de devis bloqué. */
    public function sendingSuspended(string $artisanId): bool;

    /**
     * Débite 1 crédit pour ce devis, dans le premier compartiment non vide selon l'ordre donné (RG-104) ; idempotent par
     * devis (index unique du registre). Lève `InsufficientCredits` si aucun compartiment n'est approvisionné.
     *
     * @param list<CreditBucket> $order
     */
    public function debitForQuote(string $entryId, string $artisanId, string $quoteId, array $order, \DateTimeImmutable $at): CreditBucket;

    /**
     * Q2 — restitue le crédit débité pour ce devis (demande supprimée pour fraude), dans le compartiment d'origine ;
     * idempotent (index unique du registre). Renvoie false s'il n'y avait aucun débit ou si la restitution est déjà faite.
     */
    public function refundQuote(string $entryId, string $artisanId, string $quoteId, \DateTimeImmutable $at): bool;
}
