<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

use App\Shared\Application\Bus\Query;

/** C08 — notifications in-app de l'utilisateur, filtrées par famille, paginées ; réponse : NotificationCenterView. */
final readonly class NotificationCenter implements Query
{
    public const int PER_PAGE = 20;

    public function __construct(public string $userId, public ?string $type = null, public int $page = 1)
    {
    }
}
