<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Désactiver un administrateur. */
final readonly class DisableAdmin implements Command
{
    public function __construct(public string $adminId, public string $by)
    {
    }
}
