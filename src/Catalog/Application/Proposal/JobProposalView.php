<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

final readonly class JobProposalView
{
    public const string PENDING = 'pending';

    public function __construct(
        public string $id,
        public string $jobId,
        public string $jobName,
        public string $categoryName,
        public string $categorySlug,
        public bool $newCategory,
        public string $proposedJobName,
        public ?string $proposedCategoryName,
        public string $status,
        public ?string $finalJobName,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
        public int $pendingRequests = 0,
    ) {
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }
}
