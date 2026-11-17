<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

/** Client Stripe de chaque artisan (`billing.credit_account.stripe_customer_id`, unique). */
interface StripeCustomerAccounts
{
    public function customerId(string $artisanId): ?string;

    /** Rattache le client au compte (créé s'il n'existe pas encore) ; un rattachement existant n'est jamais remplacé. */
    public function linkCustomer(string $artisanId, string $userId, string $customerId, \DateTimeImmutable $at): string;
}
