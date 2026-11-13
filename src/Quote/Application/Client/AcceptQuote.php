<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Command;

/**
 * C03.5 / RG-071 / RG-072 — le client accepte un devis (intention de commande) : demande attribuée, autres devis « non
 * retenus », coordonnées partagées (RG-022bis, E-13 / E-34).
 */
final readonly class AcceptQuote implements Command
{
    public function __construct(public string $userId, public string $quoteId)
    {
    }
}
