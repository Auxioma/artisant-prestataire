<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Changer le rôle d'un administrateur (super-admin, ré-authentification récente). */
final readonly class ChangeAdminRole implements Command
{
    public function __construct(public string $adminId, public string $role, public string $by)
    {
    }
}
