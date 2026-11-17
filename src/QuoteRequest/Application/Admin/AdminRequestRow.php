<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

final readonly class AdminRequestRow
{
    public function __construct(
        public string $id,
        public string $number,
        public string $jobName,
        public string $cityName,
        public string $status,
        public int $activeQuotes,
        public int $maxQuotes,
        public int $riskScore,
        public string $source,
        public \DateTimeImmutable $submittedAt,
        /** Artisans ayant reçu la demande (RG-062), alertes en attente de digest comprises. */
        public int $dispatched = 0,
    ) {
    }
}
