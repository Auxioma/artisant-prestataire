<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

use App\Shared\Application\Bus\Query;

/** Jauge de crédits d'un artisan (en-tête, PR01.1). Réponse : CreditGauge|null (aucun compte de crédits). */
final readonly class CreditGaugeOf implements Query
{
    public function __construct(public string $userId)
    {
    }
}
