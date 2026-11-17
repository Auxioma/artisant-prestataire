<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Command;

/** C02 « Modifier » — tant qu'aucun devis n'a été reçu (RG-066) ; métier et adresse restent ceux de l'envoi. */
final readonly class EditQuoteRequest implements Command
{
    /** @param list<string> $preferredSlots */
    public function __construct(
        public string $clientId,
        public string $requestId,
        public ?string $title,
        public ?string $summary,
        public string $description,
        public ?string $budgetRange,
        public string $timing,
        public ?string $desiredDate,
        public array $preferredSlots,
        public ?string $occupantStatus,
    ) {
    }
}
