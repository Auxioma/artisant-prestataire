<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/**
 * PR06.3 — (re)crée le lien iCal privé : le jeton, tiré par l'appelant et montré une seule fois, n'est conservé que sous
 * forme d'empreinte ; l'ancien lien cesse aussitôt de fonctionner. Jeton null : lien révoqué.
 */
final readonly class IssueIcalLink implements Command
{
    public function __construct(public string $userId, #[\SensitiveParameter] public ?string $token)
    {
    }
}
