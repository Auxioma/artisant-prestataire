<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Account;

use App\Shared\Application\Bus\Query;

/**
 * Compte utilisateur et nom d'affichage d'un artisan (profil), pour lui écrire ou communiquer ses coordonnées après
 * l'acceptation d'un devis (RG-022bis). Réponse : ArtisanAccount|null.
 */
final readonly class ArtisanAccountOf implements Query
{
    public function __construct(public string $profileId)
    {
    }
}
