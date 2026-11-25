<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Shared\Application\Bus\Query;

/** Une proposition (réponse : ?JobProposalView). */
final readonly class JobProposalOf implements Query
{
    public function __construct(public string $proposalId)
    {
    }
}
