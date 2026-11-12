<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** Un visiteur se connecte au cours du tunnel : son brouillon passe sur son compte (le plus récent l'emporte). */
final readonly class AdoptVisitorDraft implements Command
{
    public function __construct(public DraftOwner $visitor, public string $userId)
    {
    }
}
