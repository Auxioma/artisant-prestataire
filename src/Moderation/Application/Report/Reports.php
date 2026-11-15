<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

/** Signalements (`moderation.report`). */
interface Reports
{
    public function add(ReportView $report): void;

    public function hasOpen(string $reporterId, string $type, string $subjectId): bool;

    /** @return list<ReportView> signalements ouverts, du plus ancien au plus récent */
    public function open(): array;

    public function find(string $id): ?ReportView;

    public function decide(string $id, string $status, string $adminId, ?string $note, \DateTimeImmutable $at): bool;

    /** @return list<array{email: string, firstName: string, userId: string}> */
    public function reporterContact(string $id): array;
}
