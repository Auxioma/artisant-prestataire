<?php

declare(strict_types=1);

namespace App\Search\Application\Availability;

use App\Search\Application\Artisan\SearchCandidate;

/** Carte P08.2 : l'artisan et ses prochains créneaux ouverts (3 au plus, cliquables). */
final readonly class AvailableArtisan
{
    /** @param list<array{starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable}> $slots */
    public function __construct(public SearchCandidate $artisan, public array $slots)
    {
    }
}
