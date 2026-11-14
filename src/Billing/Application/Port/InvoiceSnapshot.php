<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Facture vue chez le prestataire, pour le rapprochement (aucune donnée personnelle). */
final readonly class InvoiceSnapshot
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public int $amountPaidCents,
        public ?string $billingReason,
        public ?string $subscriptionId,
    ) {
    }
}
