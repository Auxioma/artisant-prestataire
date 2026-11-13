<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Quote\Domain\Quote\QuoteStatus;

/** Un devis reçu, vu par le client (RG-112 côté artisan : aucune coordonnée avant acceptation). */
final readonly class ClientQuoteCard
{
    /**
     * @param list<int>    $vatRatesBp taux de TVA appliqués (points de base)
     * @param list<string> $mainLines  principaux postes (désignations)
     */
    public function __construct(
        public string $quoteId,
        public string $number,
        public QuoteStatus $status,
        public int $version,
        public string $artisanId,
        public string $artisanName,
        public ?string $artisanCity,
        public int $ttcCents,
        public int $htCents,
        public int $vatCents,
        public array $vatRatesBp,
        public bool $vatExempt,
        public ?int $depositPercentBp,
        public ?int $depositCents,
        public ?\DateTimeImmutable $validUntil,
        public ?string $executionDelay,
        public ?string $guarantees,
        public array $mainLines,
        public ?\DateTimeImmutable $sentAt,
        public bool $decidable,
        public ?string $decisionReason,
    ) {
    }

    public function isNew(): bool
    {
        return QuoteStatus::Sent === $this->status;
    }
}
