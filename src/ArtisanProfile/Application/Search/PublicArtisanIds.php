<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Search;

use App\Shared\Application\Bus\Query;

/** Identifiants des artisans publics (reconstruction de l'index de recherche). Réponse : list<string>. */
final readonly class PublicArtisanIds implements Query
{
}
