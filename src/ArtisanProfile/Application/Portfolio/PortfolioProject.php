<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

/** PR08.6 — projet du portfolio et ses photos (`available` : analysée, publiable). */
final readonly class PortfolioProject
{
    /** @param list<array{id: string, file_id: string, kind: string, available: bool}> $photos */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $city,
        public ?\DateTimeImmutable $doneOn,
        public ?string $jobId,
        public array $photos,
    ) {
    }
}
