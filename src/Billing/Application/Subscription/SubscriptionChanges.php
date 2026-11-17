<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * PR11.3 / §4.3 — actions de l'artisan sur son abonnement, transmises à Stripe ; l'état local suit par webhook.
 * Montée de forfait et passage mensuel → annuel : effet immédiat, prorata (`billing.proration_behavior`), crédits Q7.
 * Descente et annuel → mensuel : effet à l'échéance (calendrier Stripe), affiché en attendant. Résiliation sans frais en
 * fin de période (motif de départ facultatif, journalisé), annulable tant que la période court.
 */
final readonly class SubscriptionChanges
{
    public const array PERIODS = ['mois' => BillingInterval::Month, 'an' => BillingInterval::Year];
    public const array LEAVE_REASONS = [
        'price' => 'Le prix ne me convient pas',
        'volume' => 'Je ne reçois pas assez de demandes',
        'quality' => 'Les demandes ne correspondent pas à mon activité',
        'pause' => 'Je fais une pause dans mon activité',
        'other' => 'Autre raison',
    ];
    private const int MONTHS_PER_YEAR = 12;

    public function __construct(
        private SubscriptionReader $reader,
        private SubscriptionRepository $subscriptions,
        private PlanRepository $plans,
        private PaymentGateway $gateway,
        private SettingsProvider $settings,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function preview(string $userId, string $planId, string $period): ChangePreview
    {
        [$subscription, $current, $target, $interval] = $this->context($userId, $planId, $period);
        $immediate = $this->isImmediate($subscription, $current, $target, $interval);
        $newCredits = self::credits($target, $interval);
        $oldCredits = self::credits($current, $subscription->interval());
        $added = $immediate && $newCredits > $oldCredits
            ? ('new_full' === $this->settings->choice(SettingKey::BillingUpgradeCreditMode) ? $newCredits : $newCredits - $oldCredits)
            : 0;

        return new ChangePreview(
            $current->terms()->name,
            $this->reader->priceCents($subscription->planPriceId()) ?? 0,
            $subscription->interval(),
            PlanView::of($target, 0),
            $interval,
            $target->price($interval)->amountCents ?? 0,
            $immediate,
            $immediate ? null : $subscription->periodEnd(),
            $added,
        );
    }

    /** @return bool vrai si le changement est immédiat, faux s'il est programmé à l'échéance */
    public function change(string $userId, string $planId, string $period): bool
    {
        [$subscription, $current, $target, $interval] = $this->context($userId, $planId, $period);
        $price = $target->price($interval);
        if (null === $price || null === $price->stripePriceId) {
            throw new SubscriptionChangeRejected('Cette formule est en cours de mise en ligne : réessayez dans quelques minutes.');
        }
        $stripeId = $this->stripeId($subscription);
        $immediate = $this->isImmediate($subscription, $current, $target, $interval);
        $key = IdempotencyKey::for('ChangeSubscription', $subscription->id()->value, $price->id.'|'.($subscription->periodEnd()?->format('U') ?? ''));
        try {
            $immediate
                ? $this->gateway->changeSubscriptionPrice($stripeId, $price->stripePriceId, $this->settings->choice(SettingKey::BillingProrationBehavior), $key)
                : $this->gateway->scheduleSubscriptionPrice($stripeId, $price->stripePriceId, $key);
        } catch (ExternalServiceUnavailable) {
            throw new SubscriptionChangeRejected('Le service de paiement ne répond pas. Réessayez dans quelques instants.');
        }
        $now = $this->clock->now();
        $this->transactions->run(function () use ($subscription, $immediate, $price, $userId, $current, $target, $now): void {
            if (!$immediate && null !== $subscription->periodEnd()) {
                $subscription->scheduleChange($price->id, $subscription->periodEnd(), $now);
                $this->subscriptions->save($subscription);
            }
            $this->record($userId, 'billing.plan_change_requested', $subscription, $now, ['from_plan' => $current->id()->value, 'to_plan' => $target->id()->value, 'to_price' => $price->id, 'immediate' => $immediate]);
        });

        return $immediate;
    }

    public function cancel(string $userId, ?string $reason): void
    {
        $subscription = $this->live($userId);
        if ($subscription->cancelAtPeriodEnd()) {
            throw new SubscriptionChangeRejected('La résiliation est déjà programmée.');
        }
        $this->send($subscription, true);
        $now = $this->clock->now();
        $this->transactions->run(function () use ($subscription, $userId, $reason, $now): void {
            if (null !== $subscription->scheduledPriceId()) {
                $subscription->clearScheduledChange($now);
                $this->subscriptions->save($subscription);
            }
            $this->record($userId, 'billing.cancellation_requested', $subscription, $now, ['reason' => \array_key_exists((string) $reason, self::LEAVE_REASONS) ? $reason : null]);
        });
    }

    public function resume(string $userId): void
    {
        $subscription = $this->live($userId);
        if (!$subscription->cancelAtPeriodEnd()) {
            throw new SubscriptionChangeRejected('Aucune résiliation n’est programmée.');
        }
        if (null !== $subscription->periodEnd() && $this->clock->now() >= $subscription->periodEnd()) {
            throw new SubscriptionChangeRejected('La période est terminée : la résiliation ne peut plus être annulée.');
        }
        $this->send($subscription, false);
        $this->record($userId, 'billing.cancellation_undone', $subscription, $this->clock->now(), []);
    }

    private function send(Subscription $subscription, bool $cancel): void
    {
        try {
            $this->gateway->setCancelAtPeriodEnd($this->stripeId($subscription), $cancel, IdempotencyKey::for($cancel ? 'CancelAtPeriodEnd' : 'ResumeSubscription', $subscription->id()->value, (string) ($subscription->periodEnd()?->format('U') ?? '')));
        } catch (ExternalServiceUnavailable) {
            throw new SubscriptionChangeRejected('Le service de paiement ne répond pas. Réessayez dans quelques instants.');
        }
    }

    /** @return array{Subscription, Plan, Plan, BillingInterval} */
    private function context(string $userId, string $planId, string $period): array
    {
        $subscription = $this->live($userId);
        if ($subscription->cancelAtPeriodEnd()) {
            throw new SubscriptionChangeRejected('Une résiliation est programmée : annulez-la avant de changer de forfait.');
        }
        $current = $this->plans->find(new PlanId($subscription->planId())) ?? throw new SubscriptionChangeRejected('Forfait actuel introuvable.');
        $target = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $planId) ? $this->plans->find(new PlanId($planId)) : null;
        if (null === $target || PlanStatus::Published !== $target->status() || (PlanVisibility::Private === $target->terms()->visibility && !$target->id()->equals($current->id()))) {
            throw new SubscriptionChangeRejected('Cette formule n’est pas disponible.');
        }
        $interval = self::PERIODS[$period] ?? BillingInterval::Month;
        $price = $target->price($interval) ?? throw new SubscriptionChangeRejected('Cette formule n’est pas proposée sur cette périodicité.');
        if ($price->id === $subscription->planPriceId()) {
            throw new SubscriptionChangeRejected('C’est déjà votre formule actuelle.');
        }

        return [$subscription, $current, $target, $interval];
    }

    private function live(string $userId): Subscription
    {
        $artisanId = $this->reader->artisanOfUser($userId) ?? throw new SubscriptionChangeRejected('Aucun abonnement en cours.');
        $subscription = $this->subscriptions->liveOf($artisanId) ?? throw new SubscriptionChangeRejected('Aucun abonnement en cours.');
        if (SubscriptionStatus::Active !== $subscription->status()) {
            throw new SubscriptionChangeRejected('Réglez d’abord votre facture en attente pour modifier votre abonnement.');
        }

        return $subscription;
    }

    private function stripeId(Subscription $subscription): string
    {
        return $subscription->stripeSubscriptionId() ?? throw new SubscriptionChangeRejected('Abonnement en cours de confirmation : réessayez dans quelques instants.');
    }

    /** Montée de forfait (prix mensuel équivalent supérieur) ou passage à l'annuel : immédiat ; sinon à l'échéance. */
    private function isImmediate(Subscription $subscription, Plan $current, Plan $target, BillingInterval $interval): bool
    {
        if (BillingInterval::Month === $subscription->interval() && BillingInterval::Year === $interval) {
            return true;
        }
        if (BillingInterval::Year === $subscription->interval() && BillingInterval::Month === $interval) {
            return false;
        }

        return self::monthly($target, $interval) > self::monthly($current, $subscription->interval());
    }

    private static function monthly(Plan $plan, BillingInterval $interval): float
    {
        $cents = $plan->price($interval)->amountCents ?? 0;

        return BillingInterval::Year === $interval ? $cents / self::MONTHS_PER_YEAR : (float) $cents;
    }

    private static function credits(Plan $plan, BillingInterval $interval): int
    {
        $terms = $plan->terms();
        $perMonth = $terms->unlimited ? ($terms->fairUseCap ?? 0) : ($terms->quotesPerPeriod ?? 0);

        return BillingInterval::Year === $interval ? $perMonth * self::MONTHS_PER_YEAR : $perMonth;
    }

    /** @param array<string, scalar|null> $context */
    private function record(string $userId, string $action, Subscription $subscription, \DateTimeImmutable $now, array $context): void
    {
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'user', $userId, $action, 'subscription', $subscription->id()->value, context: $context));
    }
}
