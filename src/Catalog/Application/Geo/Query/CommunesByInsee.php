<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Communes actives parmi les codes INSEE donnés (ordre : population décroissante). Réponse : list<CommuneSummary>. */
final readonly class CommunesByInsee implements Query
{
    /** @param list<string> $insee */
    public function __construct(public array $insee)
    {
    }
}
