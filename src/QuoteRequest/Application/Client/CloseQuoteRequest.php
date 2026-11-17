<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Command;

final readonly class CloseQuoteRequest implements Command
{
    public function __construct(public string $clientId, public string $requestId, public string $reason)
    {
    }
}
