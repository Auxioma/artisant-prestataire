<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\Shared\Application\Bus\Query;

/** PR08 — données du formulaire d'édition du profil public. Réponse : EditablePublicProfileView|null. */
final readonly class EditablePublicProfile implements Query
{
    public function __construct(public string $userId)
    {
    }
}
