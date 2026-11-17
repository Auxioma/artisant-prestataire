<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Avoir avec remboursement sur le moyen de paiement d'origine (Stripe Credit Note + refund). */
final readonly class CreditNoteRequest
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $stripeInvoiceId,
        public int $amountCents,
        public bool $full,
        public string $reason,
        public string $memo,
        public array $metadata,
        public string $idempotencyKey,
    ) {
    }
}
