<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Query;

/** PR06.3 — date de création du lien iCal en service de l'artisan connecté ; réponse : DateTimeImmutable, ou null. */
final readonly class IcalLinkStatus implements Query
{
    public function __construct(public string $userId)
    {
    }
}
