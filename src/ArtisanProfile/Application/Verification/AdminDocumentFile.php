<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Query;

/** Fichier d'un justificatif de CET artisan (anti-IDOR : la pièce doit lui appartenir). Réponse : string (id de fichier)|null. */
final readonly class AdminDocumentFile implements Query
{
    public function __construct(public string $profileId, public string $documentId)
    {
    }
}
