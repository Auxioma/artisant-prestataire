<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

final readonly class ClientRequestPage
{
    /**
     * @param list<ClientRequestRow>                                                                   $items
     * @param array{total: int, waiting: int, quoted: int, completed: int, active: int, received: int} $stats
     */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage, public array $stats, public ?ClientDraft $draft)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
