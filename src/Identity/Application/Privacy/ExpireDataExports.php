<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : les archives dont le lien a expiré (7 jours) sont détruites. */
final readonly class ExpireDataExports implements Command
{
}
