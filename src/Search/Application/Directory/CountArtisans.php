<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/** Nombre d'artisans publics distincts exerçant l'un des métiers (tous si vide). Réponse : int. */
final readonly class CountArtisans implements Query
{
    /** @param list<string> $jobIds */
    public function __construct(public array $jobIds = [], public ?string $departmentCode = null)
    {
    }
}
