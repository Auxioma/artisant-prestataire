<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

final readonly class ConversationsPage
{
    /** @param list<ConversationRow> $rows */
    public function __construct(public array $rows, public int $total, public int $page, public int $perPage, public int $unread)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
