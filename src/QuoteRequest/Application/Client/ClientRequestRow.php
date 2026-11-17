<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

final readonly class ClientRequestRow
{
    public function __construct(
        public string $id,
        public string $number,
        public string $title,
        public string $jobName,
        public string $city,
        public \DateTimeImmutable $submittedAt,
        public string $status,
        public int $receivedQuotes,
        public int $maxQuotes,
        public ?string $photoId,
        public int $relaunchCount,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
