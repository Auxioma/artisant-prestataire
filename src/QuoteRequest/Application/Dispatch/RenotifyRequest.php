<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\Shared\Application\Bus\Command;

/** P-09 — une place s'est libérée : la demande revient dans le prochain digest de ses destinataires (hors exclus). */
final readonly class RenotifyRequest implements Command
{
    /** @param list<string> $excludeArtisanIds artisans à ne pas relancer (retiré, devis actif) */
    public function __construct(public string $requestId, public array $excludeArtisanIds)
    {
    }
}
