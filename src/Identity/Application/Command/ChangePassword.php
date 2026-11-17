<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Shared\Application\Bus\Command;

/** Espace « Mon compte » : le mot de passe actuel est exigé (ré-authentification, ASVS 2.5.2 / 3.7.1). */
final readonly class ChangePassword implements Command
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $currentPassword,
        #[\SensitiveParameter]
        public string $newPassword,
        public ?string $ip = null,
    ) {
    }
}
