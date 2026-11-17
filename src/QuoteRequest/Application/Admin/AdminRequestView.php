<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

final readonly class AdminRequestView
{
    /**
     * @param list<array{label: string, value: string}> $answers
     * @param list<string>                              $signals libellés des signaux de risque (RG-063)
     */
    public function __construct(
        public AdminRequestRow $row,
        public string $clientId,
        public ?string $clientName,
        public ?string $title,
        public ?string $summary,
        public string $description,
        public array $answers,
        public string $projectType,
        public string $timing,
        public ?string $targetArtisanName,
        public int $attachments,
        public array $signals,
        public ?string $statusReason,
    ) {
    }
}
