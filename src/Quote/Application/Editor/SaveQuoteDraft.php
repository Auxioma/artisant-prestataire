<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\Shared\Application\Bus\Command;

/** PR04.5 — brouillon de devis enregistré (automatiquement ou non) ; ne consomme rien (RG-100). */
final readonly class SaveQuoteDraft implements Command
{
    /** @param array<string, mixed> $input saisie de l'éditeur (voir QuoteForm) */
    public function __construct(public string $userId, public string $requestId, public string $quoteId, public array $input)
    {
    }
}
