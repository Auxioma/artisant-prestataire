<?php

declare(strict_types=1);

namespace App\Shared\Application\Review;

use App\Shared\Application\Bus\Command;

/**
 * Note moyenne et nombre d'avis publiés d'un artisan, envoyés par Review à chaque changement d'avis ; la recherche les
 * reporte dans sa projection (classement bayésien RG-012, « mieux notés » RG-024, cartes, fiche P10).
 */
final readonly class UpdateArtisanRating implements Command
{
    public function __construct(public string $artisanId, public ?float $average, public int $count)
    {
    }
}
