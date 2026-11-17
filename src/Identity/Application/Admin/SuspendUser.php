<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

use App\Shared\Application\Bus\Command;

/** BO02 — suspendre un compte (motif obligatoire, e-mail E-26) : connexion refusée, sessions fermées. */
final readonly class SuspendUser implements Command
{
    public function __construct(public string $userId, public string $adminId, public string $reason)
    {
    }
}
