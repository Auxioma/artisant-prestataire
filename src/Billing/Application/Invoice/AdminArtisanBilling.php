<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Shared\Application\Bus\Query;

/** Back-office — abonnement, factures et avoirs d'un artisan ; réponse : AdminArtisanBillingView ou null. */
final readonly class AdminArtisanBilling implements Query
{
    public function __construct(public string $artisanId)
    {
    }
}
