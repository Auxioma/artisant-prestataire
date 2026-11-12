<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

final readonly class RequestRiskInput
{
    /**
     * @param array<string, string|list<string>> $answers
     * @param list<string>                       $otherTexts titre, besoin en bref, nature des travaux (Figma P11)
     */
    public function __construct(
        public string $clientId,
        public string $jobId,
        public string $description,
        public array $answers,
        public ?string $ipHash,
        public \DateTimeImmutable $at,
        public array $otherTexts = [],
    ) {
    }
}
