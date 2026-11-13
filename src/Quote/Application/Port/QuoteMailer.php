<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

interface QuoteMailer
{
    /** E-12 — nouveau devis reçu (client). */
    public function sendNewQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, ?string $jobName, string $amountTtc, \DateTimeImmutable $validUntil): void;

    /** E-33 — demande devenue complète alors que l'artisan avait un brouillon (in-app). */
    public function notifyDraftHolder(string $userId, string $requestId, string $requestNumber, string $jobName, string $city): void;
}
