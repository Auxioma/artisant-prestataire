<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** Lectures SQL de PR11 (compte crédits, prix souscrit, registre). */
interface SubscriptionReader
{
    public function artisanOfUser(string $userId): ?string;

    public function priceCents(string $planPriceId): ?int;

    public function userOfArtisan(string $artisanId): ?string;

    /**
     * Prix de forfait correspondant à un prix Stripe.
     *
     * @return array{plan_id: string, price_id: string, interval: string}|null
     */
    public function priceByStripeId(string $stripePriceId): ?array;

    /** @return list<LedgerLine> plus récentes d'abord */
    public function history(string $artisanId, int $limit): array;
}
