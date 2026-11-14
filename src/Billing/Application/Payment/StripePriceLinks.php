<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

/** Correspondance entre les prix versionnés d'un forfait (`billing.plan_price`) et les prix Stripe. */
interface StripePriceLinks
{
    public function linkPrice(string $priceId, string $stripePriceId): void;

    /**
     * Prix remplacés (inactifs en BO08) encore en vente chez Stripe.
     *
     * @return array<string, string> identifiant local → identifiant Stripe
     */
    public function retired(string $planId): array;

    public function markRetired(string $priceId, \DateTimeImmutable $at): void;
}
