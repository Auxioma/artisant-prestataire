<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Shared\Application\Bus\Command;

/** A05.2 : nouveau mot de passe via le lien reçu (E-03). */
final readonly class ResetPassword implements Command
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        #[\SensitiveParameter]
        public string $newPassword,
        public ?string $ip = null,
    ) {
    }
}
