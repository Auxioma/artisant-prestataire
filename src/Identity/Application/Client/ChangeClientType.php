<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Shared\Application\Bus\Command;

/** C07 « Mon type de compte » : passer en compte professionnel (raison sociale, SIRET facultatif) ou revenir en particulier. */
final readonly class ChangeClientType implements Command
{
    public function __construct(public string $userId, public bool $business, public ?string $companyName = null, public ?string $siret = null)
    {
    }
}
