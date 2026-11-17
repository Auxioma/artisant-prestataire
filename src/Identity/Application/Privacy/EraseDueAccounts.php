<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** Chaque nuit : effacement des comptes dont le délai de rétractation est écoulé (RG-090, E-09). */
final readonly class EraseDueAccounts implements Command
{
}
