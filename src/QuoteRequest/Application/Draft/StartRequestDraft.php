<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/**
 * Préremplissage depuis l'URL (P11.2) : métier (/demande-de-devis/{slug-metier}) ou artisan (?pro={id}, demande
 * directe) ; P08 : date et moment souhaités du créneau cliqué (?date=AAAA-MM-JJ&creneau=morning|afternoon, le créneau
 * n'est pas réservé — RG-025) ; P09 : offre rattachée (`$offerLabel`, reprise dans la description si elle est vide).
 * Un brouillon existant est conservé ; seuls les champs fournis le complètent.
 */
final readonly class StartRequestDraft implements Command
{
    public function __construct(public DraftOwner $owner, public string $draftId, public ?string $jobId = null, public ?string $targetArtisanId = null, public ?string $source = null, public ?string $desiredDate = null, public ?string $preferredSlot = null, public ?string $offerLabel = null)
    {
    }
}
