<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.2 — import CSV « source;cible[;code] » (une ligne par redirection, en-tête facultatif). */
final readonly class ImportRedirects implements Command
{
    public function __construct(public string $adminId, public string $csv)
    {
    }
}
