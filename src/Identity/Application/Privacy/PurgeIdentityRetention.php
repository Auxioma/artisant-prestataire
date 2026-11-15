<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : appareils de connexion non revus depuis la durée de conservation des journaux, consentements expirés. */
final readonly class PurgeIdentityRetention implements Command
{
}
