<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Application\Plan\PlanView;
use App\Billing\Domain\Plan\BillingInterval;

/** Récapitulatif d'un changement de forfait avant confirmation (§4.3). */
final readonly class ChangePreview
{
    public function __construct(
        public string $currentPlanName,
        public int $currentPriceCents,
        public BillingInterval $currentInterval,
        public PlanView $target,
        public BillingInterval $interval,
        public int $priceCents,
        public bool $immediate,
        public ?\DateTimeImmutable $effectiveOn,
        public int $creditsAdded,
    ) {
    }
}
