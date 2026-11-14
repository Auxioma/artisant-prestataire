<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Application\Subscription\SubscriptionMailer;
use App\Billing\Application\Subscription\SubscriptionPeriods;
use App\Billing\Application\Subscription\SubscriptionReader;
use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionId;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Domain\Subscription\SubscriptionRuleViolation;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * ADR-0010 / §4.3 — seuls les webhooks font avancer l'abonnement et les crédits. L'abonnement Stripe est relu à chaque
 * événement (ordre d'arrivée non garanti) puis recopié : activation, renouvellement, retard, suspension, résiliation
 * programmée ou annulée, changement de prix, annulation. `invoice.paid` (création ou renouvellement) ouvre la période
 * et crédite ses devis une seule fois (facture = clé), après expiration des crédits non reportés (RG-109) ; E-40 / E-41.
 * Un échec lève une exception : le transport `stripe_webhooks` réessaie, l'événement reste « received ».
 */
final readonly class HandleStripeEventHandler implements CommandHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int MONTHS_PER_YEAR = 12;
    private const array PERIOD_REASONS = ['subscription_create' => true, 'subscription_cycle' => false];

    public function __construct(
        private ProcessedEvents $events,
        private PaymentGateway $gateway,
        private SubscriptionRepository $subscriptions,
        private SubscriptionReader $reader,
        private SubscriptionPeriods $periods,
        private PlanRepository $plans,
        private CreditLedger $ledger,
        private SubscriptionMailer $mailer,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(HandleStripeEvent $command): void
    {
        if ($this->events->isDone($command->eventId)) {
            return;
        }
        $event = $this->events->find($command->eventId);
        if (null === $event) {
            return;
        }
        $handled = match ($event->type) {
            'checkout.session.completed' => null !== $this->syncById($event->string('subscription')),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => null !== $this->syncById($event->string('id')),
            'invoice.paid' => $this->invoicePaid($event),
            'invoice.payment_failed' => $this->paymentFailed($event),
            'charge.dispute.created' => $this->dispute($event),
            default => false,
        };
        $this->events->markProcessed($event->id, !$handled, $this->clock->now());
    }

    private function syncById(?string $stripeSubscriptionId): ?Subscription
    {
        return null === $stripeSubscriptionId ? null : $this->sync($this->gateway->retrieveSubscription($stripeSubscriptionId));
    }

    /** Recopie l'état Stripe sur l'abonnement local (retrouvé par son identifiant Stripe ou par les métadonnées du tunnel). */
    private function sync(SubscriptionState $state): ?Subscription
    {
        $subscription = $this->subscriptions->findByStripeId($state->id);
        $local = $state->metadata['subscription_id'] ?? '';
        if (null === $subscription && 1 === preg_match(self::UUID, $local)) {
            $subscription = $this->subscriptions->find(new SubscriptionId($local));
        }
        if (null === $subscription) {
            return null;
        }
        $now = $this->clock->now();
        [$start, $end] = [$state->periodStart, $state->periodEnd];
        $hasPeriod = null !== $start && null !== $end && $end > $start;
        $live = $subscription->status()->isLive();
        switch ($state->status) {
            case 'active':
            case 'trialing':
                if (null !== $start && null !== $end && $hasPeriod && SubscriptionStatus::Incomplete === $subscription->status()) {
                    $subscription->activate($state->id, $start, $end, $now);
                } elseif (null !== $start && null !== $end && $hasPeriod && $live) {
                    $subscription->renew($start, $end, $now);
                }
                break;
            case 'past_due':
                if ($live) {
                    $subscription->markPastDue($now);
                }
                break;
            case 'unpaid':
            case 'paused':
                if ($live) {
                    $subscription->suspend($now);
                }
                break;
            case 'canceled':
            case 'incomplete_expired':
                if (SubscriptionStatus::Cancelled !== $subscription->status()) {
                    $subscription->cancel($now);
                }
                break;
        }
        /** @var list<SubscriptionMailer::CHANGE_*> $changes */
        $changes = [];
        if ($subscription->status()->isLive()) {
            if ($state->cancelAtPeriodEnd && !$subscription->cancelAtPeriodEnd()) {
                $subscription->scheduleCancellation($now);
                $changes[] = SubscriptionMailer::CHANGE_CANCEL_SCHEDULED;
            } elseif (!$state->cancelAtPeriodEnd && $subscription->cancelAtPeriodEnd()) {
                try {
                    $subscription->resume($now);
                    $changes[] = SubscriptionMailer::CHANGE_CANCEL_UNDONE;
                } catch (SubscriptionRuleViolation) {
                    // Période déjà échue : l'annulation suivra (customer.subscription.deleted).
                }
            }
            $price = null === $state->priceId ? null : $this->reader->priceByStripeId($state->priceId);
            if (null !== $price && $price['price_id'] !== $subscription->planPriceId()) {
                $previous = $this->plans->find(new PlanId($subscription->planId()));
                $previousInterval = $subscription->interval();
                $subscription->changePlan($price['plan_id'], $price['price_id'], BillingInterval::from($price['interval']), $now);
                $this->upgradeCredits($subscription, $previous, $previousInterval, $price['price_id'], $now);
                $changes[] = SubscriptionMailer::CHANGE_PLAN;
            }
        }
        $this->subscriptions->save($subscription);
        $this->notifyChanges($subscription, $changes);

        return $subscription;
    }

    private function invoicePaid(StripeEvent $event): bool
    {
        $stripeSubscriptionId = $event->string('parent', 'subscription_details', 'subscription') ?? $event->string('subscription');
        $invoiceId = $event->string('id');
        $subscription = $this->syncById($stripeSubscriptionId);
        if (null === $subscription || null === $invoiceId) {
            return false;
        }
        $reason = $event->string('billing_reason') ?? '';
        if (!\array_key_exists($reason, self::PERIOD_REASONS) || !$subscription->status()->isLive()) {
            return true;
        }
        $lineStart = $event->int('lines', 'data', '0', 'period', 'start');
        $lineEnd = $event->int('lines', 'data', '0', 'period', 'end');
        $start = null !== $lineStart ? new \DateTimeImmutable('@'.$lineStart) : $subscription->periodStart();
        $end = null !== $lineEnd ? new \DateTimeImmutable('@'.$lineEnd) : $subscription->periodEnd();
        $plan = $this->plans->find(new PlanId($subscription->planId()));
        if (null === $start || null === $end || null === $plan) {
            return true;
        }
        $terms = $plan->terms();
        $credits = self::credits($plan, $subscription->interval());
        $now = $this->clock->now();
        $amountPaid = $event->int('amount_paid') ?? 0;
        $periodId = $this->ids->next();
        if (!$this->periods->record($periodId, $subscription->id()->value, $invoiceId, $start, $end, $credits, $amountPaid, $now)) {
            return true;
        }
        $previous = $this->settings->bool(SettingKey::PlansRollover) ? null : $this->periods->previousOf($subscription->id()->value, $start);
        $this->ledger->grantPeriod($this->ids->next(), $subscription->artisanId(), $periodId, $credits, $end, $previous, $this->ids->next(), $now);

        $userId = $this->reader->userOfArtisan($subscription->artisanId());
        if (null !== $userId) {
            $invoiceUrl = $event->string('hosted_invoice_url');
            self::PERIOD_REASONS[$reason]
                ? $this->mailer->activated($userId, $terms->name, $credits, $end, $amountPaid, $invoiceUrl)
                : $this->mailer->renewed($userId, $terms->name, $credits, $end, $amountPaid, $invoiceUrl);
        }

        return true;
    }

    private function paymentFailed(StripeEvent $event): bool
    {
        $subscription = $this->syncById($event->string('parent', 'subscription_details', 'subscription') ?? $event->string('subscription'));
        if (null === $subscription) {
            return false;
        }
        $now = $this->clock->now();
        if (SubscriptionStatus::Active === $subscription->status()) {
            // Stripe peut signaler l'échec avant de passer l'abonnement en « past_due » : la grâce démarre dès le premier échec.
            $subscription->markPastDue($now);
            $this->subscriptions->save($subscription);
        }
        $userId = $this->reader->userOfArtisan($subscription->artisanId());
        $plan = $this->plans->find(new PlanId($subscription->planId()));
        if (null !== $userId && null !== $plan) {
            $next = $event->int('next_payment_attempt');
            $this->mailer->paymentFailed($userId, $plan->terms()->name, $event->int('amount_due') ?? 0, max(1, $event->int('attempt_count') ?? 1), null === $next ? null : new \DateTimeImmutable('@'.$next), $event->string('hosted_invoice_url'));
        }

        return true;
    }

    /** Contestation bancaire : tracée au journal pour l'équipe Finance (traitement dans le tableau de bord Stripe). */
    private function dispute(StripeEvent $event): bool
    {
        $this->audit->record(new AuditRecord($this->ids->next(), $this->clock->now(), 'system', null, 'billing.dispute_opened', 'stripe_charge', null, context: [
            'charge' => $event->string('charge'),
            'dispute' => $event->string('id'),
            'amount_cents' => $event->int('amount'),
            'reason' => $event->string('reason'),
        ]));

        return true;
    }

    /**
     * Q7 — passage à un forfait supérieur en cours de période : crédits du nouveau forfait moins ceux déjà consommés
     * (`new_minus_consumed`, soit l'écart entre les deux volumes) ou crédits complets (`new_full`). Un passage à un
     * forfait inférieur ne retire rien : il prend effet à l'échéance.
     */
    private function upgradeCredits(Subscription $subscription, ?Plan $previous, BillingInterval $previousInterval, string $newPriceId, \DateTimeImmutable $now): void
    {
        $plan = $this->plans->find(new PlanId($subscription->planId()));
        $period = $this->periods->current($subscription->id()->value);
        if (null === $plan || null === $period) {
            return;
        }
        $newCredits = self::credits($plan, $subscription->interval());
        $oldCredits = null === $previous ? $period['credits'] : self::credits($previous, $previousInterval);
        $amount = 'new_full' === $this->settings->choice(SettingKey::BillingUpgradeCreditMode) ? $newCredits : $newCredits - $oldCredits;
        if ($newCredits <= $oldCredits || $amount <= 0) {
            return;
        }
        $changeId = IdempotencyKey::uuid($subscription->id()->value.'|'.$newPriceId.'|'.$period['id']);
        $this->ledger->grantPlanChange($this->ids->next(), $subscription->artisanId(), $changeId, $period['id'], $amount, $now);
    }

    /** @param list<SubscriptionMailer::CHANGE_*> $changes */
    private function notifyChanges(Subscription $subscription, array $changes): void
    {
        if ([] === $changes) {
            return;
        }
        $userId = $this->reader->userOfArtisan($subscription->artisanId());
        $plan = $this->plans->find(new PlanId($subscription->planId()));
        if (null === $userId || null === $plan) {
            return;
        }
        foreach ($changes as $kind) {
            $this->mailer->changed($userId, $kind, $plan->terms()->name, SubscriptionMailer::CHANGE_PLAN === $kind ? $this->clock->now() : $subscription->periodEnd());
        }
    }

    /** Crédits d'une période : volume mensuel (ou plafond d'usage d'un illimité), × 12 pour un forfait annuel (P-97). */
    private static function credits(Plan $plan, BillingInterval $interval): int
    {
        $terms = $plan->terms();
        $perMonth = $terms->unlimited ? ($terms->fairUseCap ?? 0) : ($terms->quotesPerPeriod ?? 0);

        return BillingInterval::Year === $interval ? $perMonth * self::MONTHS_PER_YEAR : $perMonth;
    }
}
