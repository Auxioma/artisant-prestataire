<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Command;

/**
 * RG-072 — le client annule l'acceptation de son devis, avec motif, au plus tard
 * `quotes.cancel_acceptance_min_hours_before_appointment` heures avant le rendez-vous confirmé.
 */
final readonly class CancelAcceptance implements Command
{
    public function __construct(public string $userId, public string $quoteId, public string $reason)
    {
    }
}
