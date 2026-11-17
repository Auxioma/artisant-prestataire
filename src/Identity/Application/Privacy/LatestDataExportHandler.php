<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\QueryHandler;

final readonly class LatestDataExportHandler implements QueryHandler
{
    public function __construct(private DataExports $exports)
    {
    }

    public function __invoke(LatestDataExport $query): ?DataExportView
    {
        return $this->exports->latest($query->userId);
    }
}
