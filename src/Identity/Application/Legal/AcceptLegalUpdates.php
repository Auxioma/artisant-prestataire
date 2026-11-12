<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Shared\Application\Bus\Command;

/** RG-002 — l'utilisateur accepte les nouvelles versions en vigueur (preuve : version, empreinte, date, IP hachée). */
final readonly class AcceptLegalUpdates implements Command
{
    public function __construct(public string $userId, public ?string $ipHash)
    {
    }
}
