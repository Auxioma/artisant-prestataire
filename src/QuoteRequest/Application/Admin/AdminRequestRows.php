<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

/** Lecture de la liste BO04.1 (projection SQL : métier et commune lus dans le référentiel). */
interface AdminRequestRows
{
    public function page(AdminRequestList $query): AdminRequestPage;

    public function row(string $requestId): ?AdminRequestRow;

    public function attachmentCount(string $requestId): int;

    /** @return iterable<AdminRequestRow> toutes les lignes filtrées (export CSV) */
    public function export(AdminRequestList $query): iterable;
}
