<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** A03.4 — « rayon » autour d'une commune de départ, ou « liste » de départements et/ou de communes. */
enum ServiceAreaMode: string
{
    case Radius = 'radius';
    case List = 'list';
}
