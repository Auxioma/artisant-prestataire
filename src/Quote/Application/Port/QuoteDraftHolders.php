<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

interface QuoteDraftHolders
{
    /** @return list<string> comptes des artisans ayant un brouillon de devis sur la demande */
    public function userIdsWithDraftOn(string $requestId): array;
}
