<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\Quote\Domain\Quote\QuoteStatus;

/** PR05.1 — un devis de l'artisan : demande (RG-112 : prénom et commune), montant, dates, statut, étape d'avancement. */
final readonly class ProQuoteRow
{
    public const int STAGE_REQUEST = 1;
    public const int STAGE_SENT = 2;
    public const int STAGE_ACCEPTED = 3;
    public const int STAGE_WORKS = 4;
    public const int STAGE_DONE = 5;

    public function __construct(
        public string $quoteId,
        public string $requestId,
        public ?string $number,
        public QuoteStatus $status,
        public int $version,
        public int $ttcCents,
        public ?\DateTimeImmutable $sentAt,
        public ?\DateTimeImmutable $validUntil,
        public \DateTimeImmutable $updatedAt,
        public string $requestNumber,
        public string $title,
        public bool $requestCompleted,
        public ?string $clientFirstName,
        public string $city,
        public string $jobName,
        public ?string $budget,
        public ?string $photoId,
    ) {
    }

    /** Étape atteinte : 1 demande reçue (brouillon), 2 devis envoyé, 3 accepté, 5 terminé (4 « chantier en cours » : RDV, lot 7). */
    public function stage(): int
    {
        return match (true) {
            QuoteStatus::Accepted === $this->status && $this->requestCompleted => self::STAGE_DONE,
            QuoteStatus::Accepted === $this->status => self::STAGE_ACCEPTED,
            QuoteStatus::Draft === $this->status => self::STAGE_REQUEST,
            default => self::STAGE_SENT,
        };
    }
}
