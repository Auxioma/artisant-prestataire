<?php

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Shared\Application\Bus\Command;

/**
 * C07.2 « Appareils connectés » : déconnecte un appareil, ou tous les autres que l'appareil courant (`deviceId` null).
 * La session de l'appareil révoqué est fermée à sa requête suivante.
 */
final readonly class RevokeDevices implements Command
{
    public function __construct(public string $userId, public ?string $deviceId, public ?string $currentDeviceHash, public ?string $ip = null)
    {
    }
}
