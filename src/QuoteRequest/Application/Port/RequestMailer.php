<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

interface RequestMailer
{
    /** E-10 — demande envoyée : récapitulatif au client. */
    public function sendSubmitted(string $email, string $firstName, string $userId, string $requestId, string $number, string $jobName, string $city, int $maxQuotes): void;

    /** E-10b — demande mise en modération, ou refusée par la modération (motif normalisé). */
    public function sendModeration(string $email, string $firstName, string $userId, string $number, string $jobName, ?string $refusalReason): void;
}
