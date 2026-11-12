<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** Réactiver un administrateur. */
final readonly class EnableAdmin implements Command
{
    public function __construct(public string $adminId, public string $by)
    {
    }
}
