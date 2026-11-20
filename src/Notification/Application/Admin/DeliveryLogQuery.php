<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO11.2 — journal d'envoi filtré et paginé (réponse : DeliveryLogPage). */
final readonly class DeliveryLogQuery implements Query
{
    public const int PER_PAGE = 50;

    public function __construct(public ?string $code = null, public ?string $status = null, public ?string $recipient = null, public ?string $channel = null, public int $page = 1)
    {
    }
}
