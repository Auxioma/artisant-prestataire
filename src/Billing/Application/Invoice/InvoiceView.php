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
    ) {
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'paid' => 'Payée',
            'open' => 'En attente',
            'uncollectible' => 'Échouée',
            'void' => 'Annulée',
            default => 'Brouillon',
        };
    }
}
