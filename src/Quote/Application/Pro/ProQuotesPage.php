<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

final readonly class ProQuotesPage
{
    /**
     * @param list<ProQuoteRow>     $items
     * @param array<string, int>    $stats pending, accepted, works, done
     * @param array<string, int>    $tabs  devis, chantiers
     * @param array<string, string> $jobs  métiers de l'artisan (filtre)
     */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage, public array $stats, public array $tabs, public array $jobs)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
