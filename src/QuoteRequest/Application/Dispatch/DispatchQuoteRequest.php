<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\Shared\Application\Bus\Command;

/** RG-062 — diffuse une demande publiée ; rejouable sans double alerte (nouveaux éligibles seulement). */
final readonly class DispatchQuoteRequest implements Command
{
    public function __construct(public string $requestId)
    {
    }
}
