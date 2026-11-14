<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

/** Avoir (Stripe Credit Note) : montant de l'avoir, montant remboursé, motif, auteur (null : émis depuis Stripe). */
final readonly class CreditNoteRecord
{
    public function __construct(
        public string $stripeCreditNoteId,
        public string $artisanId,
        public ?string $number,
        public int $amountCents,
        public int $refundedCents,
        public ?string $reason,
        public ?string $comment,
        public ?string $adminId,
        public ?string $pdfUrl,
        public ?string $invoiceNumber = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}
