<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Shared\Application\Bus\Command;

/** A02 / A03 étape 1. L'identifiant est fourni par l'appelant (UUID v7) pour ouvrir la session aussitôt. */
final readonly class RegisterUser implements Command
{
    public function __construct(
        public string $userId,
        public string $email,
        #[\SensitiveParameter]
        public string $password,
        public string $kind,
        public string $firstName,
        public string $lastName,
        public ?string $ip = null,
    ) {
    }
}
