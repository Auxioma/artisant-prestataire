<?php

declare(strict_types=1);

namespace App\Administration\Application\Exports;

interface ScheduledExportStore
{
    public function add(ScheduledExport $export): void;

    public function find(string $id): ?ScheduledExport;

    /** @return list<ScheduledExport> plus récents d'abord */
    public function latest(int $limit): array;

    /** @return list<ScheduledExport> */
    public function expired(\DateTimeImmutable $now): array;

    public function delete(string $id): void;
}
