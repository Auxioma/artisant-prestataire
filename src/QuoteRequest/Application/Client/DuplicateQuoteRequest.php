<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Command;

/** C02 « Dupliquer » — recopie une demande dans le brouillon du client (remplace le brouillon en cours), sans les photos. */
final readonly class DuplicateQuoteRequest implements Command
{
    public function __construct(public string $clientId, public string $requestId, public string $draftId)
    {
    }
}
