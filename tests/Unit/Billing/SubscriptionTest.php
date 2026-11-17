<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionId;
use App\Billing\Domain\Subscription\SubscriptionRuleViolation;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

/** §4.3 — cycle de l'abonnement : en attente → actif → en retard / suspendu → renouvelé ; résiliation annulable. */
final class SubscriptionTest extends TestCase
{
    private static function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' 10:00:00 UTC');
    }

    private static function started(): Subscription
    {
        return Subscription::start(new SubscriptionId('0190f5e4-0000-7000-8000-00000000e601'), 'artisan-fictif', 'plan', 'price', BillingInterval::Month, self::at('2026-11-14'));
    }

    public function testOnlyStripeConfirmationActivates(): void
    {
        $subscription = self::started();
        self::assertSame(SubscriptionStatus::Incomplete, $subscription->status());
        self::assertFalse($subscription->status()->isLive());

        $subscription->activate('sub_FICTIF', self::at('2026-11-14'), self::at('2026-12-14'), self::at('2026-11-14'));
        self::assertSame([SubscriptionStatus::Active, 'sub_FICTIF'], [$subscription->status(), $subscription->stripeSubscriptionId()]);
    }

    public function testPaymentFailureThenRenewal(): void
    {
        $subscription = self::started();
        $subscription->activate('sub_FICTIF', self::at('2026-11-14'), self::at('2026-12-14'), self::at('2026-11-14'));
        $subscription->markPastDue(self::at('2026-12-15'));
        self::assertSame(SubscriptionStatus::PastDue, $subscription->status());
        $subscription->suspend(self::at('2026-12-22'));
        self::assertSame(SubscriptionStatus::Suspended, $subscription->status());

        $subscription->renew(self::at('2026-12-14'), self::at('2027-01-14'), self::at('2026-12-23'));
        self::assertSame(SubscriptionStatus::Active, $subscription->status());
        self::assertSame('2027-01-14', $subscription->periodEnd()?->format('Y-m-d'));
    }

    public function testCancellationCanBeUndoneWhileThePeriodRuns(): void
    {
        $subscription = self::started();
        $subscription->activate('sub_FICTIF', self::at('2026-11-14'), self::at('2026-12-14'), self::at('2026-11-14'));
        $subscription->scheduleCancellation(self::at('2026-11-20'));
        self::assertTrue($subscription->cancelAtPeriodEnd());
        $subscription->resume(self::at('2026-11-21'));
        self::assertFalse($subscription->cancelAtPeriodEnd());

        $subscription->scheduleCancellation(self::at('2026-11-22'));
        $this->expectException(SubscriptionRuleViolation::class);
        $subscription->resume(self::at('2026-12-15'));
    }

    public function testPendingSubscriptionCannotBeCancelledInAdvance(): void
    {
        $this->expectException(SubscriptionRuleViolation::class);
        self::started()->scheduleCancellation(self::at('2026-11-15'));
    }

    public function testCancelledSubscriptionIsNotReactivated(): void
    {
        $subscription = self::started();
        $subscription->cancel(self::at('2026-11-15'));
        $this->expectException(SubscriptionRuleViolation::class);
        $subscription->activate('sub_FICTIF', self::at('2026-11-14'), self::at('2026-12-14'), self::at('2026-11-16'));
    }
}
