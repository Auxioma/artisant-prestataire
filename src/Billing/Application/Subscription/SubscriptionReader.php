<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** Lectures SQL de PR11 (compte crédits, prix souscrit, registre). */
interface SubscriptionReader
{
    public function artisanOfUser(string $userId): ?string;

    public function priceCents(string $planPriceId): ?int;

    public function userOfArtisan(string $artisanId): ?string;

    /** L'artisan a-t-il déjà eu un abonnement payé (codes réservés aux nouveaux abonnés) ? */
    public function hadSubscription(string $artisanId): bool;

    /** Nom du forfait d'un prix (« Pro — annuel »). */
    public function planNameOfPrice(string $planPriceId): ?string;

    /**
     * Prix de forfait correspondant à un prix Stripe.
     *
     * @return array{plan_id: string, price_id: string, interval: string}|null
     */
    public function priceByStripeId(string $stripePriceId): ?array;

    /** @return list<LedgerLine> plus récentes d'abord */
    public function history(string $artisanId, int $limit): array;
}
