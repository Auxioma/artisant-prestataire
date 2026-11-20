<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Search\Application\Artisan\SearchCandidate;

final readonly class DirectoryResult
{
    /**
     * @param list<SearchCandidate> $artisans
     * @param list<string>          $letters  initiales présentes dans tout l'annuaire
     */
    public function __construct(public array $artisans, public int $total, public int $page, public int $perPage, public array $letters)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
