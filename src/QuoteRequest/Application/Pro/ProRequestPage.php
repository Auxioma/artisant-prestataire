<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

final readonly class ProRequestPage
{
    /**
     * @param list<ProRequestCard>            $items
     * @param array{unread: int, urgent: int} $stats « Mes demandes en chiffres » (non lues, urgentes ouvertes)
     */
    public function __construct(public ProArtisan $artisan, public array $items, public int $total, public int $page, public int $perPage, public array $stats = ['unread' => 0, 'urgent' => 0])
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
