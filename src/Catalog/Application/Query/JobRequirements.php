<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** Justificatifs et assurances exigés par métier (A03.3, A03.5, A03.6, Q16 — paramétrés en BO06). Réponse : list<JobRequirement>. */
final readonly class JobRequirements implements Query
{
    /** @param list<string> $jobIds */
    public function __construct(public array $jobIds)
    {
    }
}
