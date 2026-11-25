<?php

declare(strict_types=1);

namespace App\Search\Application\Availability;

use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryHandler;

/**
 * RG-025 — seuls les créneaux « ouverts » de l'agenda (non réservés, non bloqués, prévenance respectée) sont proposés.
 * Ordre : créneau le plus proche, puis distance, puis note.
 */
final readonly class AvailableArtisansHandler implements QueryHandler
{
    private const int CANDIDATES = 500;

    public function __construct(private ArtisanSearchIndex $index)
    {
    }

    /** @return list<AvailableArtisan> */
    public function __invoke(AvailableArtisans $query): array
    {
        $candidates = null === $query->communeInsee
            ? $this->index->featured($query->jobIds, null, 0, self::CANDIDATES)
            : $this->index->candidates($query->communeInsee, $query->radiusKm, $query->jobIds, []);
        if ([] === $candidates) {
            return [];
        }
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->profileId] = $candidate;
        }
        $slots = $this->index->openSlots(array_keys($byId), $query->from, $query->to, $query->partOfDay, AvailableArtisans::SLOTS_PER_ARTISAN);

        $cards = [];
        foreach ($slots as $id => $list) {
            if (isset($byId[$id]) && [] !== $list) {
                $cards[] = new AvailableArtisan($byId[$id], $list);
            }
        }
        usort($cards, static fn (AvailableArtisan $a, AvailableArtisan $b): int => [$a->slots[0]['starts_at'], $a->artisan->distanceKm, -($a->artisan->ratingAverage ?? 0)]
            <=> [$b->slots[0]['starts_at'], $b->artisan->distanceKm, -($b->artisan->ratingAverage ?? 0)]);

        return \array_slice($cards, 0, max(1, $query->limit));
    }
}
