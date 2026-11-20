<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

use App\Shared\Domain\Storage\FileType;

/** Fichier contrôlé (et ré-encodé s'il s'agit d'une image), prêt pour la quarantaine. */
final readonly class SanitizedFile
{
    public function __construct(public string $path, public FileType $type)
    {
    }
}
