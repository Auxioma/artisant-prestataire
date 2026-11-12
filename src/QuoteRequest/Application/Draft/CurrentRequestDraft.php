<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Query;

/** Brouillon du propriétaire (null : aucun). Réponse : ?RequestDraftView. */
final readonly class CurrentRequestDraft implements Query
{
    public function __construct(public DraftOwner $owner)
    {
    }
}
