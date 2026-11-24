<?php

declare(strict_types=1);

namespace App\Search\Application\Availability;

use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryHandler;

/** Mêmes créneaux que la page « Disponibilités » (P08) : ouverts, prévenance respectée, agenda en pause exclu. */
final readonly class ArtisanOpenSlotsHandler implements QueryHandler
{
    public function __construct(private ArtisanSearchIndex $index)
    {
    }

    /** @return array<string, list<array{starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable}>> */
    public function __invoke(ArtisanOpenSlots $query): array
    {
        $to = $query->from->modify('+'.ArtisanOpenSlots::DAYS.' days');
        $days = [];
        foreach ($this->index->openSlots([$query->artisanId], $query->from, $to, null, ArtisanOpenSlots::MAX_SLOTS)[$query->artisanId] ?? [] as $slot) {
            $days[$slot['starts_at']->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d')][] = $slot;
        }

        return $days;
    }
}
