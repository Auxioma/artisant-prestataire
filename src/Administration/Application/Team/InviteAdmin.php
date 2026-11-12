<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Command;

/** BO14.1 — inviter un administrateur ; le jeton (généré par l'appelant pour lui afficher le lien en console) n'est conservé que haché. `invitedBy` null : amorçage en console. */
final readonly class InviteAdmin implements Command
{
    public function __construct(public string $adminId, public string $email, public string $name, public string $role, #[\SensitiveParameter] public string $token, public ?string $invitedBy)
    {
    }
}
