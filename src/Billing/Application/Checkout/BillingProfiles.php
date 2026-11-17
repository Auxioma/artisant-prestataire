<?php

declare(strict_types=1);

namespace App\Billing\Application\Checkout;

/** Dernières informations de facturation de l'artisan (`billing.billing_profile`). */
interface BillingProfiles
{
    public function find(string $artisanId): ?BillingDetails;

    public function save(string $artisanId, BillingDetails $details, ?\DateTimeImmutable $vatVerifiedAt, \DateTimeImmutable $at): void;
}
