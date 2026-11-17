<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\Shared\Application\Bus\Command;

/** PR08.2 / PR08.3 — nom commercial, slogan, description, points forts, langues, expérience, clients acceptés. */
final readonly class UpdatePublicPresentation implements Command
{
    /**
     * @param list<string> $strengths
     * @param list<string> $languages
     * @param list<string> $clients
     */
    public function __construct(
        public string $userId,
        public ?string $tradeName,
        public ?string $slogan,
        public ?string $description,
        public array $strengths,
        public array $languages,
        public ?int $yearsExperience,
        public array $clients,
    ) {
    }
}
