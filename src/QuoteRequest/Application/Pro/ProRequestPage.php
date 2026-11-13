<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

final readonly class ProRequestPage
{
    /** @param list<ProRequestCard> $items */
    public function __construct(public ProArtisan $artisan, public array $items, public int $total, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
