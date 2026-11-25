<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Shared\Application\Bus\Query;

/**
 * BO06 — propositions en attente (une ligne par métier proposé, la plus ancienne d'abord, avec le nombre de demandes
 * qui l'attendent) puis les dernières décisions. Réponse : list<JobProposalView>. `countOnly` : int (en attente).
 */
final readonly class JobProposals implements Query
{
    public const int RECENT_DECISIONS = 20;

    public function __construct(public bool $countOnly = false)
    {
    }
}
