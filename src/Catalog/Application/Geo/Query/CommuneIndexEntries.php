<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Communes actives à indexer pour l'autocomplétion de lieu (handler : list<CommuneSummary> avec nom du département). */
final readonly class CommuneIndexEntries implements Query
{
}
