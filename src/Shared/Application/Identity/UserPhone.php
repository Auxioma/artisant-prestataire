<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

use App\Shared\Application\Bus\Query;

/**
 * Téléphone d'un compte actif (E.164), pour l'affichage au clic d'une fiche artisan (RG-030). Traitée par Identity ;
 * réponse : string, ou null si le compte n'a pas de numéro, n'est pas actif ou est anonymisé.
 */
final readonly class UserPhone implements Query
{
    public function __construct(public string $userId)
    {
    }
}
