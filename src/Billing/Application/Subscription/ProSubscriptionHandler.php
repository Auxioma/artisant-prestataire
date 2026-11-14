<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

/**
 * PR11 — situation (forfait en vigueur ou offre de lancement), avantages et options du forfait, grille des forfaits
 * proposés (P13) avec le forfait actuel, registre des crédits (PR11.2, RG-107).
 */
final readonly class ProSubscriptionHandler implements QueryHandler
{
    public function __construct(
        private SubscriptionReader $reader,
        private SubscriptionRepository $subscriptions,
        private PlanRepository $plans,
        private CreditLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function __invoke(ProSubscription $query): ?ProSubscriptionView
    {
        $artisanId = $this->reader->artisanOfUser($query->userId);
        $balance = $this->ledger->balanceOfUser($query->userId);
        if (null === $artisanId || null === $balance) {
            return null;
        }
        $live = $this->subscriptions->liveOf($artisanId);
        $currentPlan = null === $live ? null : $this->plans->find(new PlanId($live->planId()));
        $summary = null === $live || null === $currentPlan ? null : new SubscriptionSummary(
            $currentPlan->id()->value,
            $currentPlan->terms()->name,
            $this->reader->priceCents($live->planPriceId()) ?? 0,
            $live->interval(),
            $live->status(),
            $live->periodStart(),
            $live->periodEnd(),
            $live->cancelAtPeriodEnd(),
        );
        $offered = array_values(array_filter(
            $this->plans->all(),
            static fn (Plan $p): bool => (PlanStatus::Published === $p->status() && PlanVisibility::Public === $p->terms()->visibility) || $p->id()->value === $currentPlan?->id()->value,
        ));

        return new ProSubscriptionView(
            $artisanId,
            $balance,
            $summary,
            null === $currentPlan ? null : PlanView::of($currentPlan, 0),
            array_map(static fn (Plan $p): PlanView => PlanView::of($p, 0), $offered),
            $this->reader->history($artisanId, $query->historyLimit),
            $this->clock->now(),
        );
    }
}
