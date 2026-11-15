<?php

declare(strict_types=1);

namespace App\Content\Application\Retention;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : messages de contact (P16) de plus de 3 ans supprimés, pièces jointes détruites (CDC §11). */
final readonly class PurgeContactMessages implements Command
{
}
