<?php

declare(strict_types=1);

namespace App\Billing\Application\Checkout;

use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Port\PromotionCode;
use App\Billing\Domain\Plan\BillingInterval;

/** PR12.1 — récapitulatif avant paiement : forfait, périodicité, prix HT, crédits inclus, facturation pré-remplie. */
final readonly class CheckoutPreview
{
    public function __construct(
        public PlanView $plan,
        public BillingInterval $interval,
        public int $priceCents,
        public ?BillingDetails $billing,
        public ?PromotionCode $promotion,
        public bool $paymentOpen,
    ) {
    }

    /** Périodicités proposées (annuelle seulement si un prix annuel est fixé). */
    public function hasYearly(): bool
    {
        return null !== $this->plan->yearlyCents;
    }
}
