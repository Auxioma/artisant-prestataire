<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

use App\Shared\Application\Bus\Command;

/** PR08.6 — ajoute une photo (avant, après ou simple) : ré-encodée, analysée, publiée une fois saine. */
final readonly class AddPortfolioPhoto implements Command
{
    public function __construct(public string $userId, public string $projectId, public string $path, public ?string $clientName, public string $kind)
    {
    }
}
