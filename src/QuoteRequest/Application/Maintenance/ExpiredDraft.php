<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Maintenance;

/** Brouillon arrivé au terme de sa conservation (RG-060, `requests.draft_retention_days`) et ses pièces. */
final readonly class ExpiredDraft
{
    /** @param list<string> $files */
    public function __construct(public string $draftId, public array $files)
    {
    }
}
