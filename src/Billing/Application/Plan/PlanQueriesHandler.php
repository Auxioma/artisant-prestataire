<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Shared\Application\Bus\QueryHandler;

final readonly class PlanQueriesHandler implements QueryHandler
{
    public function __construct(private PlanRepository $plans)
    {
    }

    /** @return list<PlanView> */
    public function __invoke(AdminPlans|PublishedPlans $query): array
    {
        $plans = $this->plans->all();
        if ($query instanceof PublishedPlans) {
            $plans = array_values(array_filter($plans, static fn (Plan $p): bool => PlanStatus::Published === $p->status() && PlanVisibility::Public === $p->terms()->visibility));
        }

        return array_map(fn (Plan $p): PlanView => PlanView::of($p, $this->plans->subscribers($p->id())), $plans);
    }
}
