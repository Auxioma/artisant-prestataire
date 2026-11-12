<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Submission;

use App\Shared\Application\Bus\Command;

/**
 * P11.7 — envoi d'une demande. `address` (adresse d'intervention complète) sert uniquement à l'empreinte anti-doublon
 * RG-065 ; son stockage chiffré et sa révélation (RG-022bis) arrivent avec le tunnel (T-402).
 * `broadcastToOthers` à null : valeur par défaut `requests.direct_broadcast_default`.
 */
final readonly class SubmitQuoteRequest implements Command
{
    /**
     * @param array<string, string|list<string>> $answers
     * @param list<string>                       $preferredSlots
     */
    public function __construct(
        public string $requestId,
        public string $clientId,
        public string $jobId,
        public string $cityInsee,
        public string $postalCode,
        public string $address,
        public string $projectType,
        public string $description,
        public array $answers,
        public ?string $budgetRange,
        public string $timing,
        public ?string $desiredDate,
        public ?string $targetArtisanId = null,
        public ?bool $broadcastToOthers = null,
        public ?string $ipHash = null,
        public array $preferredSlots = [],
        public ?string $occupantStatus = null,
        public string $contactPreference = 'messaging_only',
        public ?int $questionnaireVersion = null,
    ) {
    }
}
