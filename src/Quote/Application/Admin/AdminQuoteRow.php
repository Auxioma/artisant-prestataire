<?php

declare(strict_types=1);

namespace App\Quote\Application\Admin;

use App\Quote\Domain\Quote\QuoteStatus;

final readonly class AdminQuoteRow
{
    public function __construct(
        public string $quoteId,
        public string $number,
        public QuoteStatus $status,
        public int $version,
        public int $ttcCents,
        public ?\DateTimeImmutable $sentAt,
        public ?\DateTimeImmutable $validUntil,
        public string $requestId,
        public string $requestNumber,
        public string $jobName,
        public string $city,
        public string $artisanName,
    ) {
    }
}
