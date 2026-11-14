<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Application\Credit\CreditBalance;
use App\Billing\Application\Invoice\InvoiceView;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Port\PaymentMethodSummary;

final readonly class ProSubscriptionView
{
    /**
     * @param list<PlanView>    $plans    forfaits proposés (publiés et publics), forfait actuel signalé par $current
     * @param list<LedgerLine>  $history  plus récentes d'abord
     * @param list<InvoiceView> $invoices plus récentes d'abord
     */
    public function __construct(
        public string $artisanId,
        public CreditBalance $balance,
        public ?SubscriptionSummary $subscription,
        public ?PlanView $current,
        public array $plans,
        public array $history,
        public \DateTimeImmutable $now,
        public array $invoices = [],
        public ?PaymentMethodSummary $paymentMethod = null,
        public bool $hasCustomer = false,
    ) {
    }

    public function isCurrent(PlanView $plan): bool
    {
        return $plan->id === $this->current?->id;
    }
}
