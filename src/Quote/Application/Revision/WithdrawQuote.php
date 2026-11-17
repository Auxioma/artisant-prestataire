<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Shared\Application\Bus\Command;

/** RG-103 — retrait d'un devis par l'artisan, avant consultation : place libérée, crédit non restitué. */
final readonly class WithdrawQuote implements Command
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
