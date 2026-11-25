<?php

declare(strict_types=1);

namespace App\Search\Application\Availability;

use App\Shared\Application\Bus\Query;

/**
 * P10 (maquette V2 « Fiche artisan publique ») — carte « Disponibilités » : créneaux ouverts (RG-025) d'un artisan sur
 * les DAYS prochains jours. Réponse : array<string, list<array{starts_at, ends_at}>> par jour local (Y-m-d).
 */
final readonly class ArtisanOpenSlots implements Query
{
    public const int DAYS = 14;
    public const int MAX_SLOTS = 120;

    public function __construct(public string $artisanId, public \DateTimeImmutable $from)
    {
    }
}
