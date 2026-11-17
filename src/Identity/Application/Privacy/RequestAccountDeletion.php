<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** C07.4 « Supprimer mon compte » : mot de passe exigé ; compte désactivé pendant le délai de rétractation (RG-090). */
final readonly class RequestAccountDeletion implements Command
{
    public function __construct(public string $userId, #[\SensitiveParameter] public string $password, public ?string $ip = null)
    {
    }
}
