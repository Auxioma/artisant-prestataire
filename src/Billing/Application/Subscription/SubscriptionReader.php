<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** Lectures SQL de PR11 (compte crédits, prix souscrit, registre). */
interface SubscriptionReader
{
    public function artisanOfUser(string $userId): ?string;

    public function priceCents(string $planPriceId): ?int;

    /** @return list<LedgerLine> plus récentes d'abord */
    public function history(string $artisanId, int $limit): array;
}
