<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

/** PR11 / PR13.1 — ligne de facture affichée à l'artisan. */
final readonly class InvoiceView
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $status,
        public int $amountHtCents,
        public int $vatCents,
        public int $amountTtcCents,
        public \DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public bool $archived,
        public ?string $hostedUrl,
        public int $amountPaidCents = 0,
        public int $refundedCents = 0,
    ) {
    }

    /** Montant encore remboursable (payé moins les avoirs remboursés). */
    public function refundableCents(): int
    {
        return 'paid' === $this->status ? max(0, $this->amountPaidCents - $this->refundedCents) : 0;
    }

    public function statusLabel(): string
    {
        if ('paid' === $this->status && $this->refundedCents > 0) {
            return $this->refundedCents >= $this->amountPaidCents ? 'Remboursée' : 'Remboursée en partie';
        }

        return match ($this->status) {
            'paid' => 'Payée',
            'open' => 'En attente',
            'uncollectible' => 'Échouée',
            'void' => 'Annulée',
            default => 'Brouillon',
        };
    }
}
