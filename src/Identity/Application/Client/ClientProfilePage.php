<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Shared\Application\Bus\Query;

/** C07 « Mon profil » : informations du titulaire (réponse : ClientProfileView, null si ce n'est pas un client). */
final readonly class ClientProfilePage implements Query
{
    public function __construct(public string $userId)
    {
    }
}
