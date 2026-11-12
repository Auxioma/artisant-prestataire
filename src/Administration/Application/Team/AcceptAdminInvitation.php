<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Invitation acceptée : mot de passe, secret TOTP confirmé par un code, codes de secours (affichés une fois). */
final readonly class AcceptAdminInvitation implements Command
{
    /** @param list<string> $backupCodes */
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        #[\SensitiveParameter]
        public string $password,
        #[\SensitiveParameter]
        public string $totpSecret,
        public string $totpCode,
        #[\SensitiveParameter]
        public array $backupCodes,
    ) {
    }
}
