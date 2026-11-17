<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Query;

/** PR06.3 — contenu du lien iCal privé ; réponse : IcalFeedView, ou null si le jeton est inconnu ou révoqué. */
final readonly class IcalFeed implements Query
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }
}
