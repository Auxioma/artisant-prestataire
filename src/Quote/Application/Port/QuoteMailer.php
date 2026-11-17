<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

interface QuoteMailer
{
    /** E-12 — nouveau devis reçu (client). */
    public function sendNewQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, ?string $jobName, string $amountTtc, \DateTimeImmutable $validUntil, bool $reminder = false): void;

    /** E-33 — demande devenue complète alors que l'artisan avait un brouillon (in-app). */
    /** E-12b — devis révisé (RG-102). */
    public function sendRevisedQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, int $version, string $amountTtc, \DateTimeImmutable $validUntil): void;

    public function notifyDraftHolder(string $userId, string $requestId, string $requestNumber, string $jobName, string $city): void;

    /** E-35b — le client a ouvert le devis (in-app à l'artisan). */
    public function quoteViewed(string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber): void;

    /**
     * E-34 — devis accepté, à l'artisan, avec les coordonnées du client (RG-022bis).
     *
     * @param array{name: string, email: ?string, phone: ?string, address: ?string} $client
     */
    public function quoteAccepted(string $email, string $firstName, string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber, string $amountTtc, array $client): void;

    /**
     * E-13 — récapitulatif d'acceptation au client, avec les coordonnées de l'artisan.
     *
     * @param array{name: string, email: ?string, phone: ?string} $artisan
     */
    public function acceptanceSummary(string $email, string $firstName, string $clientUserId, string $requestId, string $quoteNumber, string $requestNumber, string $amountTtc, array $artisan): void;

    /** E-35 — devis refusé par le client (motif) ou non retenu (un autre a été accepté). */
    public function quoteDeclined(string $email, string $firstName, string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber, bool $refused, ?string $reason): void;
}
