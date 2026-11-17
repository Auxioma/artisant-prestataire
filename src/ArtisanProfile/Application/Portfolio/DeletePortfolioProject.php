<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

use App\Shared\Application\Bus\Command;

/** PR08.6 — supprime un projet et détruit ses photos. */
final readonly class DeletePortfolioProject implements Command
{
    public function __construct(public string $userId, public string $projectId)
    {
    }
}
