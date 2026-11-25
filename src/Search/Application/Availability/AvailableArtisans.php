<?php

declare(strict_types=1);

namespace App\Search\Application\Availability;

use App\Shared\Application\Bus\Query;

/**
 * P08 « Disponibilités » (/disponibilites) : artisans ayant un créneau ouvert (RG-025) entre `$from` et `$to`, autour
 * d'une commune (rayon) ou partout, filtrés par métier et moment de la journée. Réponse : list<AvailableArtisan>.
 */
final readonly class AvailableArtisans implements Query
{
    public const string MORNING = 'morning';
    public const string AFTERNOON = 'afternoon';
    public const int SLOTS_PER_ARTISAN = 3;

    /** @param list<string> $jobIds */
    public function __construct(
        public array $jobIds,
        public ?string $communeInsee,
        public int $radiusKm,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?string $partOfDay = null,
        public int $limit = 24,
    ) {
    }
}
