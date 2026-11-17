<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Command;

/** C03.5 — refus motivé d'un devis par le client (motif obligatoire, commentaire facultatif) ; E-35 à l'artisan. */
final readonly class RefuseQuote implements Command
{
    public function __construct(public string $userId, public string $quoteId, public string $reason, public ?string $comment)
    {
    }
}
