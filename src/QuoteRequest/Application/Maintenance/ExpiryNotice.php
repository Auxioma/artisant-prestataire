<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Maintenance;

/** E-19 — demande ouverte ou complète proche de son échéance (RG-064). */
final readonly class ExpiryNotice
{
    public function __construct(
        public string $requestId,
        public string $clientId,
        public string $number,
        public string $jobName,
        public string $city,
        public \DateTimeImmutable $expiresAt,
        public int $receivedQuotes,
    ) {
    }
}
