<?php

declare(strict_types=1);

namespace App\Billing\Domain\Subscription;

interface SubscriptionRepository
{
    public function find(SubscriptionId $id): ?Subscription;

    public function findByStripeId(string $stripeSubscriptionId): ?Subscription;

    /** Abonnement en vigueur (actif, en retard ou suspendu) de l'artisan, s'il existe. */
    public function liveOf(string $artisanId): ?Subscription;

    /** @return list<Subscription> abonnements en retard ou suspendus (période de grâce en cours ou écoulée) */
    public function inArrears(): array;

    public function save(Subscription $subscription): void;
}
