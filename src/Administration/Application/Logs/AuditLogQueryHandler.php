<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class AuditLogQueryHandler implements QueryHandler
{
    private const int MAX_PER_PAGE = 1000;

    public function __construct(private AuditLogReader $reader, private Clock $clock)
    {
    }

    public function __invoke(AuditLogQuery $query): AuditLogPage
    {
        $perPage = max(1, min($query->perPage, self::MAX_PER_PAGE));
        $page = max(1, $query->page);
        $result = $this->reader->page($query->filter, $this->clock->now(), ($page - 1) * $perPage, $perPage);

        return new AuditLogPage($result['rows'], $result['total'], $page, $perPage, $this->reader->modules(), $this->reader->admins());
    }
}
