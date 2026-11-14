<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * §4.3 — après un échec de paiement, l'artisan garde l'envoi de devis pendant `billing.grace_days` ; au-delà,
 * l'abonnement est suspendu (envoi bloqué, RG-106) et E-45 est envoyé, une seule fois. Après `billing.cancel_after_days`
 * sans paiement, l'abonnement est annulé chez Stripe (le webhook « deleted » le confirme ici). Un paiement réussi entre
 * deux passages remet l'abonnement « actif » (webhook), il n'est donc plus concerné.
 */
final readonly class EnforceBillingGraceHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PlanRepository $plans,
        private SubscriptionReader $reader,
        private SubscriptionMailer $mailer,
        private PaymentGateway $gateway,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(EnforceBillingGrace $command): void
    {
        $now = $this->clock->now();
        $graceDays = $this->settings->int(SettingKey::BillingGraceDays);
        $cancelDays = $this->settings->int(SettingKey::BillingCancelAfterDays);
        foreach ($this->subscriptions->inArrears() as $subscription) {
            $since = $subscription->pastDueSince();
            if (null === $since) {
                continue;
            }
            $stripeId = $subscription->stripeSubscriptionId();
            if (null !== $stripeId && $since->modify(\sprintf('+%d days', $cancelDays)) <= $now) {
                try {
                    $this->gateway->cancelSubscription($stripeId, IdempotencyKey::for('CancelForArrears', $subscription->id()->value, $stripeId));
                } catch (ExternalServiceUnavailable) {
                    continue; // nouvelle tentative au prochain passage
                }
                $this->record('billing.subscription_cancelled_arrears', $subscription->id()->value, $now, $cancelDays);

                continue;
            }
            if (SubscriptionStatus::PastDue === $subscription->status() && $since->modify(\sprintf('+%d days', $graceDays)) <= $now) {
                $subscription->suspend($now);
                $this->subscriptions->save($subscription);
                $this->record('billing.subscription_suspended', $subscription->id()->value, $now, $graceDays);
                $userId = $this->reader->userOfArtisan($subscription->artisanId());
                $plan = $this->plans->find(new PlanId($subscription->planId()));
                if (null !== $userId && null !== $plan) {
                    $this->mailer->suspended($userId, $plan->terms()->name, $graceDays, $since->modify(\sprintf('+%d days', $cancelDays)));
                }
            }
        }
    }

    private function record(string $action, string $subscriptionId, \DateTimeImmutable $now, int $days): void
    {
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, $action, 'subscription', $subscriptionId, context: ['days' => $days]));
    }
}
