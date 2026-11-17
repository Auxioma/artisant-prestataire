<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

use App\Shared\Application\Bus\Command;

/** PR08.6 — crée (`creation`) ou modifie un projet : titre, description, ville, date de réalisation, métier. */
final readonly class SavePortfolioProject implements Command
{
    public function __construct(
        public string $userId,
        public string $projectId,
        public bool $creation,
        public string $title,
        public ?string $description,
        public ?string $city,
        public ?string $doneOn,
        public ?string $jobId,
    ) {
    }
}
