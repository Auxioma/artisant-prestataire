<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/**
 * PR02.2 — carte d'une demande : jamais l'identité ni l'adresse du client (RG-112), seulement la commune et la
 * distance depuis la commune de départ de l'artisan (RG-022).
 */
final readonly class ProRequestCard
{
    public function __construct(
        public string $id,
        public string $number,
        public string $jobName,
        public string $title,
        public string $city,
        public ?int $distanceKm,
        public string $timing,
        public bool $urgent,
        public ?string $budget,
        public \DateTimeImmutable $submittedAt,
        public int $photos,
        public bool $direct,
        public int $quotesSent,
        public int $maxQuotes,
        public RequestState $state,
        /** RG-112 : prénom seul. */
        public ?string $clientFirstName = null,
        public string $excerpt = '',
        /** Première photo disponible (vignette PR02, accès contrôlé). */
        public ?string $photoId = null,
        /** Raison de la diffusion (`direct`, `eligible`, `extended`) ; null pour l'aperçu Q15. */
        public ?string $dispatchReason = null,
    ) {
    }

    public function placesLeft(): int
    {
        return max(0, $this->maxQuotes - $this->quotesSent);
    }

    /** Peut encore recevoir un devis de cet artisan (lot 5 : PR04). */
    public function answerable(): bool
    {
        return \in_array($this->state, [RequestState::Unread, RequestState::Viewed], true) && $this->placesLeft() > 0;
    }
}
