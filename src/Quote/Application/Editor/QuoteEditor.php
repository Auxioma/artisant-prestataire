<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\Shared\Application\Bus\Query;

/** PR04 — éditeur de devis de l'artisan connecté sur une demande ; null s'il ne peut pas y répondre. */
final readonly class QuoteEditor implements Query
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
