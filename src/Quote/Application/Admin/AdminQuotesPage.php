<?php

declare(strict_types=1);

namespace App\Quote\Application\Admin;

final readonly class AdminQuotesPage
{
    /**
     * @param list<AdminQuoteRow> $items
     * @param array<string, int>  $counts devis par statut
     */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage, public array $counts)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
