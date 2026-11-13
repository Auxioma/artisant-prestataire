<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Query;

/** C03.3 / C03.4 — devis reçus sur une demande du client connecté. Réponse : ClientQuotes|null (pas sa demande). */
final readonly class ClientRequestQuotes implements Query
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
