<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

final readonly class DataExportView
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $status,
        public ?string $fileId,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $readyAt,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public function downloadable(\DateTimeImmutable $now): bool
    {
        return 'ready' === $this->status && null !== $this->fileId && null !== $this->expiresAt && $this->expiresAt > $now;
    }
}
