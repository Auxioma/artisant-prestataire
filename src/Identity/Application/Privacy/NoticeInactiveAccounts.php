<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** CDC §11 — relance des comptes inactifs (E-09b) et programmation de l'effacement des relances restées sans suite. */
final readonly class NoticeInactiveAccounts implements Command
{
}
