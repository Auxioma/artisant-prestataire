<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/** PR03.1 — contenu complet d'une demande pour l'artisan : prénom et commune du client seulement (RG-112). */
final readonly class ProRequestView
{
    /**
     * @param list<array{label: string, value: string}> $answers
     * @param list<string>                              $photos         identifiants des photos (visionneuse, accès contrôlé)
     * @param list<string>                              $preferredSlots
     */
    public function __construct(
        public ProRequestCard $card,
        public ?string $clientFirstName,
        public string $projectType,
        public ?string $summary,
        public ?string $workNature,
        public string $description,
        public array $answers,
        public array $photos,
        public ?\DateTimeImmutable $desiredDate,
        public array $preferredSlots,
        public ?string $occupantStatus,
        public bool $ignoredByMe,
        /** @var list<array{body: string, createdAt: \DateTimeImmutable}> compléments ajoutés par le client (C02) */
        public array $addenda = [],
    ) {
    }
}
