<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

/** Classement P07 et paramètres de la méthode, affichés en clair (obligation de transparence). */
final readonly class TopRatedResult
{
    /** @param list<TopRatedEntry> $entries */
    public function __construct(public array $entries, public float $mean, public int $weight, public int $minReviews)
    {
    }
}
