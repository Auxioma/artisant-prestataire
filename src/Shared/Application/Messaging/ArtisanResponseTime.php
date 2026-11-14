<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

use App\Shared\Application\Bus\Query;

/**
 * PR07.1 / P10 — délai moyen de réponse d'un artisan, calculé sur les premiers messages des clients. Traitée par
 * Messaging ; réponse : nombre de minutes, ou null tant que l'échantillon est insuffisant.
 */
final readonly class ArtisanResponseTime implements Query
{
    public function __construct(public string $artisanId)
    {
    }
}
