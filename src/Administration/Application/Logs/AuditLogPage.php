<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

final readonly class AuditLogPage
{
    /**
     * @param list<AuditLogEntry>   $rows
     * @param list<string>          $modules
     * @param array<string, string> $admins
     */
    public function __construct(public array $rows, public int $total, public int $page, public int $perPage, public array $modules, public array $admins)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
