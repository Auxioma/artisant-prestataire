<?php

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Shared\Application\Bus\Query;

/** Réponse : list<ConnectedDevice>, l'appareil courant en tête. */
final readonly class ConnectedDevices implements Query
{
    public function __construct(public string $userId, public ?string $currentDeviceHash)
    {
    }
}
