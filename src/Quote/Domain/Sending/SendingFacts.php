<?php

declare(strict_types=1);

namespace App\Quote\Domain\Sending;

use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\QuoteStatus;

/** Faits établis sous verrou (demande puis compte de crédits) au moment de l'envoi (ARCHITECTURE §6.1). */
final readonly class SendingFacts
{
    /** @param list<MentionCheck> $mentions */
    public function __construct(
        public bool $requestOpen,
        public int $activeQuotes,
        public int $maxQuotes,
        public bool $artisanVerified,
        public QuoteStatus $quoteStatus,
        public array $mentions,
        public int $creditsAvailable,
    ) {
    }
}
