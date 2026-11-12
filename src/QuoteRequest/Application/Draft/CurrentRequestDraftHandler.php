<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\QueryHandler;

final readonly class CurrentRequestDraftHandler implements QueryHandler
{
    public function __construct(private DraftStore $drafts)
    {
    }

    public function __invoke(CurrentRequestDraft $query): ?RequestDraftView
    {
        return $this->drafts->find($query->owner);
    }
}
