<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Command;

/** C02 « Ajouter un complément » — visible des artisans (PR03), sans coordonnées. */
final readonly class AddRequestAddendum implements Command
{
    public function __construct(public string $clientId, public string $requestId, public string $addendumId, public string $body)
    {
    }
}
