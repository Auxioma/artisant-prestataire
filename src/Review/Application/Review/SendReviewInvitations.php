<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Shared\Application\Bus\Command;

/** Chaque jour : E-16 un jour après l'ouverture de la fenêtre de dépôt (RG-034), relance sept jours plus tard. */
final readonly class SendReviewInvitations implements Command
{
}
