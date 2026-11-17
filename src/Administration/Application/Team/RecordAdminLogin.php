<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Connexion complète (mot de passe + 2FA) : horodatage et journal d'audit. */
final readonly class RecordAdminLogin implements Command
{
    public function __construct(public string $adminId, public ?string $ip)
    {
    }
}
