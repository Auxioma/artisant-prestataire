<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

use App\Shared\Application\Bus\Command;

/** BO02 — lever la suspension d'un compte (e-mail E-26). */
final readonly class ReinstateUser implements Command
{
    public function __construct(public string $userId, public string $adminId)
    {
    }
}
