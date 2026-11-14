<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Subscription\SubscriptionReader;
use App\Billing\Application\Subscription\SubscriptionSummary;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

final readonly class AdminArtisanBillingHandler implements QueryHandler
{
    private const int LIMIT = 100;

    public function __construct(
        private Invoices $invoices,
        private SubscriptionReader $reader,
        private SubscriptionRepository $subscriptions,
        private PlanRepository $plans,
        private QueryBus $queries,
    ) {
    }

    public function __invoke(AdminArtisanBilling $query): ?AdminArtisanBillingView
    {
        $userId = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->artisanId) ? $this->reader->userOfArtisan($query->artisanId) : null;
        if (null === $userId) {
            return null;
        }
        $contact = $this->queries->ask(new UserContact($userId));
        $live = $this->subscriptions->liveOf($query->artisanId);
        $plan = null === $live ? null : $this->plans->find(new PlanId($live->planId()));
        $summary = null === $live || null === $plan ? null : new SubscriptionSummary(
            $plan->id()->value,
            $plan->terms()->name,
            $this->reader->priceCents($live->planPriceId()) ?? 0,
            $live->interval(),
            $live->status(),
            $live->periodStart(),
            $live->periodEnd(),
            $live->cancelAtPeriodEnd(),
        );

        return new AdminArtisanBillingView(
            $query->artisanId,
            $contact instanceof UserContactDetails ? trim($contact->firstName.' '.$contact->lastName) : null,
            $contact instanceof UserContactDetails ? $contact->email : null,
            $summary,
            $this->invoices->ofArtisan($query->artisanId, self::LIMIT),
            $this->invoices->creditNotesOf($query->artisanId),
        );
    }
}
