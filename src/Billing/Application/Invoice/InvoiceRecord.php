<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

/** Réplique d'une facture Stripe (ADR-0009) : montants en centimes, HT / TVA / TTC. */
final readonly class InvoiceRecord
{
    public function __construct(
        public string $stripeInvoiceId,
        public string $customerId,
        public ?string $stripeSubscriptionId,
        public ?string $number,
        public string $status,
        public string $currency,
        public int $amountHtCents,
        public int $vatCents,
        public int $amountTtcCents,
        public int $amountPaidCents,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public \DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $paidAt,
        public ?string $hostedUrl,
        public ?string $pdfUrl,
    ) {
    }
}
