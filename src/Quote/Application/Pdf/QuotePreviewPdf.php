<?php

declare(strict_types=1);

namespace App\Quote\Application\Pdf;

use App\Shared\Application\Bus\Query;

/** PR04.4 — aperçu PDF du brouillon de l'artisan connecté sur une demande ; null s'il n'y en a pas. */
final readonly class QuotePreviewPdf implements Query
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
