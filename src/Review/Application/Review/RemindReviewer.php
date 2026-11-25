<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Shared\Application\Bus\Command;

/** PR09.3 — l'artisan relance une fois le client d'une prestation sans avis (E-16, fenêtre RG-034 ouverte). */
final readonly class RemindReviewer implements Command
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
