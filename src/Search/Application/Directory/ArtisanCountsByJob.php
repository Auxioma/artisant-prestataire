<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/**
 * Nombre d'artisans publics par métier : en France, dans un département, ou desservant une commune (zone ET rayon,
 * RG-021). Réponse : array<string, int> (id du métier => nombre).
 */
final readonly class ArtisanCountsByJob implements Query
{
    public function __construct(public ?string $departmentCode = null, public ?string $communeInsee = null, public ?int $radiusKm = null)
    {
    }
}
