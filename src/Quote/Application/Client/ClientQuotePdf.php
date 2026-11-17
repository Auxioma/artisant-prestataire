<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Query;

/** C03.3 — PDF figé de la version courante d'un devis reçu (empreinte vérifiée). Réponse : string|null. */
final readonly class ClientQuotePdf implements Query
{
    public function __construct(public string $userId, public string $quoteId)
    {
    }
}
