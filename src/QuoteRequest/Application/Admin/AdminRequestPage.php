<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

/** BO04.1 — page de la liste et indicateurs (Figma 186:1669 : en modération, ouvertes, complètes, sans devis > 48 h). */
final readonly class AdminRequestPage
{
    /**
     * @param list<AdminRequestRow>                                                        $items
     * @param array{all: int, pending: int, open: int, full: int, stale: int, quotes: int} $kpis
     */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage, public array $kpis)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
