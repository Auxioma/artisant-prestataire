<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

use App\Shared\Application\Bus\Command;

/** C05 / P10 — ajoute (`$favorite` vrai) ou retire un artisan des favoris du client. */
final readonly class ToggleFavorite implements Command
{
    public function __construct(public string $userId, public string $artisanId, public bool $favorite)
    {
    }
}
