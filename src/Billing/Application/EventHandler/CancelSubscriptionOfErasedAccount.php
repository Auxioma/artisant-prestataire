<?php

declare(strict_types=1);

namespace App\Billing\Application\EventHandler;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Subscription\SubscriptionReader;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-090 / §11 — compte artisan effacé : l'abonnement en cours est résilié immédiatement chez Stripe (plus aucun
 * prélèvement), sans remboursement automatique du mois entamé ; l'état local suit par le webhook
 * `customer.subscription.deleted`, ou tout de suite s'il n'existe pas d'abonnement Stripe. Factures conservées 10 ans.
 * Une panne Stripe laisse l'événement en échec : Messenger le rejoue.
 */
final readonly class CancelSubscriptionOfErasedAccount implements EventHandler
{
    public function __construct(
        private SubscriptionReader $reader,
        private SubscriptionRepository $subscriptions,
        private PaymentGateway $gateway,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(AccountErased $event): void
    {
        $artisanId = $this->reader->artisanOfUser($event->userId);
        $subscription = null === $artisanId ? null : $this->subscriptions->liveOf($artisanId);
        if (null === $subscription) {
            return;
        }
        $now = $this->clock->now();
        $stripeId = $subscription->stripeSubscriptionId();
        if (null === $stripeId) {
            $subscription->cancel($now);
            $this->subscriptions->save($subscription);
        } else {
            $this->gateway->cancelSubscription($stripeId, IdempotencyKey::for('CancelForErasure', $subscription->id()->value, $stripeId));
        }
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'billing.subscription_cancelled_erasure', 'subscription', $subscription->id()->value));
    }
}
