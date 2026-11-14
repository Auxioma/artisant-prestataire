<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Shared\Application\Bus\Query;

/** PR11 — page « Mes abonnements » de l'artisan connecté ; réponse : ProSubscriptionView, null hors compte crédits. */
final readonly class ProSubscription implements Query
{
    public function __construct(public string $userId, public int $historyLimit = 50)
    {
    }
}
