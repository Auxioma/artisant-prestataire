<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanTerms;

final readonly class PlanView
{
    public function __construct(
        public string $id,
        public string $slug,
        public PlanTerms $terms,
        public int $position,
        public PlanStatus $status,
        public ?int $monthlyCents,
        public ?int $yearlyCents,
        public int $subscribers,
        public bool $deletable,
    ) {
    }

    public static function of(Plan $plan, int $subscribers): self
    {
        return new self(
            $plan->id()->value,
            $plan->slug(),
            $plan->terms(),
            $plan->position(),
            $plan->status(),
            $plan->price(BillingInterval::Month)?->amountCents,
            $plan->price(BillingInterval::Year)?->amountCents,
            $subscribers,
            !$plan->everPublished() && 0 === $subscribers,
        );
    }
}
