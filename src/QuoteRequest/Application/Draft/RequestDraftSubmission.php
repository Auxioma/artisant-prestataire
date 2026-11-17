<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/**
 * P11.7 — « Envoyer ma demande » avant la validation de l'e-mail (RG-040) : la demande partira automatiquement à
 * l'activation du compte. `broadcastToOthers` : choix du récapitulatif pour une demande directe.
 */
final readonly class RequestDraftSubmission implements Command
{
    public function __construct(public DraftOwner $owner, public ?bool $broadcastToOthers = null)
    {
    }
}
