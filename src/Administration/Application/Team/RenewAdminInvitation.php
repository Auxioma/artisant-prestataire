<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Renvoyer une invitation (nouveau lien, l'ancien est révoqué). */
final readonly class RenewAdminInvitation implements Command
{
    public function __construct(public string $adminId, #[\SensitiveParameter] public string $token, public ?string $by)
    {
    }
}
