<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

use App\Shared\Application\Bus\Query;

/** C04.1 — conversations de l'utilisateur ; réponse : ConversationsPage. */
final readonly class Conversations implements Query
{
    public const array FILTERS = ['all', 'unread', 'archived'];
    public const int PER_PAGE = 20;

    public function __construct(public string $userId, public string $filter = 'all', public string $query = '', public int $page = 1)
    {
    }
}
