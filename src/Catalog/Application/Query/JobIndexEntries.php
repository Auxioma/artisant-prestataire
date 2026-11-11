<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** Métiers actifs (catégorie racine active) à indexer pour la recherche (RG-010) ; tous si $jobIds est null. */
final readonly class JobIndexEntries implements Query
{
    /**
     * @param list<string>|null $jobIds
     */
    public function __construct(public ?array $jobIds = null)
    {
    }
}
