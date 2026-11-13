<?php

declare(strict_types=1);

namespace App\Quote\Application\Sending;

use App\Shared\Application\Bus\Command;

/** PR04.5 — envoi du devis de l'artisan connecté sur une demande (RG-100, RG-101). */
final readonly class SendQuote implements Command
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
