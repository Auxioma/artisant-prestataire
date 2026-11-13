<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Dispatch\DispatchSummary;

interface RequestMailer
{
    /** E-10 — demande envoyée : récapitulatif au client. */
    public function sendSubmitted(string $email, string $firstName, string $userId, string $requestId, string $number, string $jobName, string $city, int $maxQuotes): void;

    /** E-10b — demande mise en modération, ou refusée par la modération (motif normalisé). */
    public function sendModeration(string $email, string $firstName, string $userId, string $number, string $jobName, ?string $refusalReason): void;

    /** E-31 (demande directe) ou E-30 (nouvelle demande compatible) — alerte immédiate à un artisan. */
    public function sendNewRequest(string $email, string $firstName, string $userId, DispatchSummary $request, bool $direct): void;

    /**
     * E-30 regroupé — nouvelles demandes compatibles depuis le dernier digest.
     *
     * @param non-empty-list<DispatchSummary> $requests
     */
    public function sendDigest(string $email, string $firstName, string $userId, array $requests): void;
}
