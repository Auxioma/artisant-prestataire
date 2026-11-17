<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\Shared\Application\Bus\Command;

/** PR03 ouvert : la demande passe de « non lue » à « consultée » (gratuit, RG-110). */
final readonly class MarkRequestViewed implements Command
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
