<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

final readonly class ConversationsPage
{
    /**
     * @param list<ConversationRow>                                                     $rows
     * @param array{all: int, unread: int, clients: int, prospects: int, archived: int} $counts compteurs des onglets
     */
    public function __construct(public array $rows, public int $total, public int $page, public int $perPage, public int $unread, public array $counts = ['all' => 0, 'unread' => 0, 'clients' => 0, 'prospects' => 0, 'archived' => 0])
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
