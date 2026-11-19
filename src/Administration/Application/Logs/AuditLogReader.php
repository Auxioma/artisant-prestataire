<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

interface AuditLogReader
{
    /**
     * Entrées les plus récentes d'abord.
     *
     * @return array{total: int, rows: list<AuditLogEntry>}
     */
    public function page(AuditLogFilter $filter, \DateTimeImmutable $now, int $offset, int $limit): array;

    /** @return list<string> modules présents dans le journal (préfixe des actions : identity, billing…) */
    public function modules(): array;

    /** @return array<string, string> administrateurs : id => nom */
    public function admins(): array;
}
