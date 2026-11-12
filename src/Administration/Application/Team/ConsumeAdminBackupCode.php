<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Code de secours utilisé à la connexion : consommé (usage unique). */
final readonly class ConsumeAdminBackupCode implements Command
{
    public function __construct(public string $adminId, #[\SensitiveParameter] public string $code)
    {
    }
}
