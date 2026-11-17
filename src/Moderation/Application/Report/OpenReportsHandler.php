<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

use App\Shared\Application\Bus\QueryHandler;

final readonly class OpenReportsHandler implements QueryHandler
{
    public function __construct(private Reports $reports)
    {
    }

    /** @return list<ReportView> */
    public function __invoke(OpenReports $query): array
    {
        return $this->reports->open();
    }
}
