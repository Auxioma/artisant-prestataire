<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Command;

/** RG-064 — relance unique d'une demande expirée : nouvelle échéance, diffusion aux nouveaux éligibles. */
final readonly class RelaunchQuoteRequest implements Command
{
    public function __construct(public string $clientId, public string $requestId)
    {
    }
}
