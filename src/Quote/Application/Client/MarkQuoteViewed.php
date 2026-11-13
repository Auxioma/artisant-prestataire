<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Command;

/** C03.3 — le client ouvre un devis : « consulté » (visible par l'artisan, E-35b) ; sans effet ensuite. */
final readonly class MarkQuoteViewed implements Command
{
    public function __construct(public string $userId, public string $quoteId)
    {
    }
}
