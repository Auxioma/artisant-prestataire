<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** Préremplissage depuis l'URL (P11.2) : métier (/demande-de-devis/{slug-metier}) ou artisan (?pro={id}, demande directe). Un brouillon existant est conservé ; seuls les champs fournis le complètent. */
final readonly class StartRequestDraft implements Command
{
    public function __construct(public DraftOwner $owner, public string $draftId, public ?string $jobId = null, public ?string $targetArtisanId = null)
    {
    }
}
