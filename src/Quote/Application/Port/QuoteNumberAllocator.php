<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

/** PR04.1 — séquence annuelle des devis d'un artisan (sans trou pour les brouillons : attribuée à l'envoi). */
interface QuoteNumberAllocator
{
    public function next(string $artisanId, int $year): int;
}
