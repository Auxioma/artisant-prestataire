<?php

declare(strict_types=1);

namespace App\Shared\Application\Billing;

use App\Shared\Application\Bus\Query;

/** Crédits restants (tous compartiments) de comptes artisans, traité par Billing. Réponse : array<string, int> (par compte). */
final readonly class RemainingCredits implements Query
{
    /** @param list<string> $userIds */
    public function __construct(public array $userIds)
    {
    }
}
