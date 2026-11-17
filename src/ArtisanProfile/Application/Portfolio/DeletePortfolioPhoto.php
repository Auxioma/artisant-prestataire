<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

use App\Shared\Application\Bus\Command;

/** PR08.6 — retire une photo et détruit le fichier. */
final readonly class DeletePortfolioPhoto implements Command
{
    public function __construct(public string $userId, public string $photoId)
    {
    }
}
