<?php

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Shared\Application\Bus\Command;

/** Connexion réussie (A04) : date de dernière connexion, appareil, alerte E-05 si l'appareil est nouveau. */
final readonly class RecordLogin implements Command
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $deviceToken,
        public ?string $userAgent,
        public ?string $ip,
        public ?string $ipHash,
        public string $method,
    ) {
    }
}
