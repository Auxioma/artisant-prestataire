<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

use App\Shared\Application\Bus\Query;

/** BO15.1 — une page du journal (réponse : AuditLogPage). */
final readonly class AuditLogQuery implements Query
{
    public function __construct(public AuditLogFilter $filter, public int $page = 1, public int $perPage = 25)
    {
    }
}
