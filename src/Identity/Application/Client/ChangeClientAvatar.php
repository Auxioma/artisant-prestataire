<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Shared\Application\Bus\Command;

/** C07 : photo de profil (bouton appareil photo) ; chemin null = retirer la photo. */
final readonly class ChangeClientAvatar implements Command
{
    public function __construct(public string $userId, public ?string $path, public ?string $clientName = null)
    {
    }
}
