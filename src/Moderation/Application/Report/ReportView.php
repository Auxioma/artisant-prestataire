<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

final readonly class ReportView
{
    public function __construct(
        public string $id,
        public string $type,
        public string $subjectId,
        public string $title,
        public string $excerpt,
        public ?string $ownerId,
        public ?string $reporterId,
        public string $reason,
        public ?string $details,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?string $reporterName = null,
        public int $similar = 0,
    ) {
    }

    public function reasonLabel(): string
    {
        return ReportReason::tryFrom($this->reason)?->label() ?? $this->reason;
    }
}
