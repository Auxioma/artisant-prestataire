<?php

declare(strict_types=1);

namespace App\Quote\Application\Pdf;

use App\Shared\Application\Bus\Query;

/** PDF figé d'une version envoyée, pour l'artisan auteur du devis ; null sinon. */
final readonly class QuoteVersionPdf implements Query
{
    public function __construct(public string $userId, public string $quoteId, public int $version)
    {
    }
}
