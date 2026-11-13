<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Pro\ProRequestCard;

/** PR04 — tout ce qu'affiche l'éditeur : demande (RG-112), entreprise, brouillon et totaux, contrôle RG-120, crédits. */
final readonly class QuoteEditorView
{
    /** @param list<MentionCheck> $mentions */
    public function __construct(
        public ProRequestCard $request,
        public QuoteIssuerView $issuer,
        public ?string $quoteId,
        public QuoteStatus $status,
        public ?string $number,
        public ?QuoteContent $content,
        public array $mentions,
        public ?int $creditsRemaining,
        public int $defaultValidityDays,
        public ?\DateTimeImmutable $savedAt,
    ) {
    }

    public function complete(): bool
    {
        foreach ($this->mentions as $check) {
            if (!$check->ok) {
                return false;
            }
        }

        return true;
    }
}
